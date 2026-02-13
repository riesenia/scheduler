use rayon::prelude::*;
use serde::{Deserialize, Serialize};
use std::collections::HashMap;
use std::io::Read;
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::{Duration, Instant};

/// How many levels deep to use parallel branching.
/// Below this depth, we switch to sequential backtracking.
const PARALLEL_DEPTH: usize = 3;

// ── JSON protocol ───────────────────────────────────────────────────────────

#[derive(Deserialize)]
struct Input {
    items: Vec<i32>,
    terms: Vec<TermInput>,
    #[serde(default)]
    timeout: Option<f64>,
}

#[derive(Deserialize)]
struct TermInput {
    id: usize,
    from: i64,
    to: i64,
    #[serde(default)]
    locked_id: Option<i32>,
}

#[derive(Serialize)]
#[serde(tag = "status")]
enum Output {
    #[serde(rename = "ok")]
    Ok { assignments: Vec<AssignmentOut> },

    #[serde(rename = "invalid_item")]
    InvalidItem {
        message: String,
        term_id: usize,
        item_id: i32,
    },

    #[serde(rename = "conflict")]
    Conflict {
        message: String,
        conflicts: Vec<usize>,
    },

    #[serde(rename = "timeout")]
    Timeout { message: String },
}

#[derive(Serialize, Clone)]
struct AssignmentOut {
    term_id: usize,
    item_id: i32,
}

// ── Solver state ────────────────────────────────────────────────────────────

#[derive(Clone)]
struct State {
    /// term_index -> assigned item_index (None = unassigned)
    assignments: Vec<Option<usize>>,
    /// item_index -> list of term_indices currently assigned to it
    item_terms: Vec<Vec<usize>>,
}

impl State {
    fn assign(&mut self, ti: usize, ii: usize) {
        self.assignments[ti] = Some(ii);
        self.item_terms[ii].push(ti);
    }

    fn unassign(&mut self, ti: usize, ii: usize) {
        self.assignments[ti] = None;
        self.item_terms[ii].pop();
    }
}

// ── Main ────────────────────────────────────────────────────────────────────

fn main() {
    let mut buf = String::new();
    std::io::stdin()
        .read_to_string(&mut buf)
        .expect("failed to read stdin");

    let input: Input = match serde_json::from_str(&buf) {
        Ok(v) => v,
        Err(e) => {
            emit(&Output::Conflict {
                message: format!("Invalid JSON input: {e}"),
                conflicts: vec![],
            });
            std::process::exit(1);
        }
    };

    emit(&solve(input));
}

fn emit(output: &Output) {
    println!(
        "{}",
        serde_json::to_string(output).expect("failed to serialize output")
    );
}

// ── Solve ───────────────────────────────────────────────────────────────────

fn solve(input: Input) -> Output {
    let n_items = input.items.len();
    let n_terms = input.terms.len();

    if n_items == 0 || n_terms == 0 {
        return Output::Conflict {
            message: "Set at least one item and term".into(),
            conflicts: vec![],
        };
    }

    let item_ids = &input.items;
    let terms = &input.terms;

    // item_id -> item_index
    let item_idx: HashMap<i32, usize> = item_ids.iter().enumerate().map(|(i, &id)| (id, i)).collect();

    // Precompute conflict matrix (overlap check = O(1) during search)
    let conflicts: Vec<Vec<bool>> = (0..n_terms)
        .map(|i| {
            (0..n_terms)
                .map(|j| i != j && terms[i].from <= terms[j].to && terms[j].from <= terms[i].to)
                .collect()
        })
        .collect();

    // Initial state
    let mut state = State {
        assignments: vec![None; n_terms],
        item_terms: vec![vec![]; n_items],
    };

    // ── Locked terms ────────────────────────────────────────────────────────
    for ti in 0..n_terms {
        if let Some(locked_id) = terms[ti].locked_id {
            let ii = match item_idx.get(&locked_id) {
                Some(&idx) => idx,
                None => {
                    return Output::InvalidItem {
                        message: format!("Term locked to unknown item: {locked_id}"),
                        term_id: terms[ti].id,
                        item_id: locked_id,
                    };
                }
            };

            for &other in &state.item_terms[ii] {
                if conflicts[ti][other] {
                    return Output::Conflict {
                        message: format!("Conflict in terms for item {locked_id}"),
                        conflicts: vec![terms[ti].id, terms[other].id],
                    };
                }
            }

            state.assign(ti, ii);
        }
    }

    // ── Unlocked terms (sorted by MRV – most conflicts first) ───────────────
    let mut unlocked: Vec<usize> = (0..n_terms)
        .filter(|&ti| terms[ti].locked_id.is_none())
        .collect();

    unlocked.sort_by(|&a, &b| {
        let ca = conflicts[a].iter().filter(|&&c| c).count();
        let cb = conflicts[b].iter().filter(|&&c| c).count();
        cb.cmp(&ca) // descending – most constrained first
    });

    if unlocked.is_empty() {
        return build_ok(&state, terms, item_ids);
    }

    // ── Search ──────────────────────────────────────────────────────────────
    let found = AtomicBool::new(false);
    let timed_out = AtomicBool::new(false);
    let deadline = input.timeout.map(|secs| Instant::now() + Duration::from_secs_f64(secs));

    match par_search(&unlocked, 0, &state, &conflicts, n_items, &found, &timed_out, deadline) {
        Some(final_state) => build_ok(&final_state, terms, item_ids),
        None if timed_out.load(Ordering::Relaxed) => Output::Timeout {
            message: "Scheduler timeout exceeded".into(),
        },
        None => {
            let ids: Vec<usize> = unlocked.iter().map(|&ti| terms[ti].id).collect();
            Output::Conflict {
                message: "Conflict in terms".into(),
                conflicts: ids,
            }
        }
    }
}

fn build_ok(state: &State, terms: &[TermInput], item_ids: &[i32]) -> Output {
    Output::Ok {
        assignments: state
            .assignments
            .iter()
            .enumerate()
            .map(|(ti, &ii)| AssignmentOut {
                term_id: terms[ti].id,
                item_id: item_ids[ii.expect("all terms must be assigned")],
            })
            .collect(),
    }
}

// ── Parallel search (top levels) ────────────────────────────────────────────

fn par_search(
    unlocked: &[usize],
    idx: usize,
    state: &State,
    conflicts: &[Vec<bool>],
    n_items: usize,
    found: &AtomicBool,
    timed_out: &AtomicBool,
    deadline: Option<Instant>,
) -> Option<State> {
    if idx >= unlocked.len() {
        return Some(state.clone());
    }
    if found.load(Ordering::Relaxed) || timed_out.load(Ordering::Relaxed) {
        return None;
    }
    if deadline.is_some_and(|d| Instant::now() >= d) {
        timed_out.store(true, Ordering::Relaxed);
        return None;
    }

    let ti = unlocked[idx];
    let valid = valid_items(ti, state, conflicts, n_items);

    if valid.is_empty() {
        return None;
    }

    // Use parallel branching at the top levels of the search tree
    if idx < PARALLEL_DEPTH && valid.len() > 1 {
        valid.par_iter().find_map_any(|&ii| {
            if found.load(Ordering::Relaxed) || timed_out.load(Ordering::Relaxed) {
                return None;
            }
            let mut s = state.clone();
            s.assign(ti, ii);
            if !forward_check(unlocked, idx + 1, &s, conflicts, n_items) {
                return None;
            }
            let result = par_search(unlocked, idx + 1, &s, conflicts, n_items, found, timed_out, deadline);
            if result.is_some() {
                found.store(true, Ordering::Relaxed);
            }
            result
        })
    } else {
        // Switch to sequential backtracking (no more cloning)
        let mut s = state.clone();
        if seq_search(unlocked, idx, &mut s, conflicts, n_items, found, timed_out, deadline) {
            Some(s)
        } else {
            None
        }
    }
}

// ── Sequential search (deeper levels) ───────────────────────────────────────

fn seq_search(
    unlocked: &[usize],
    idx: usize,
    state: &mut State,
    conflicts: &[Vec<bool>],
    n_items: usize,
    found: &AtomicBool,
    timed_out: &AtomicBool,
    deadline: Option<Instant>,
) -> bool {
    if idx >= unlocked.len() {
        return true;
    }
    if found.load(Ordering::Relaxed) || timed_out.load(Ordering::Relaxed) {
        return false;
    }
    if deadline.is_some_and(|d| Instant::now() >= d) {
        timed_out.store(true, Ordering::Relaxed);
        return false;
    }

    let ti = unlocked[idx];
    let valid = valid_items(ti, state, conflicts, n_items);

    for ii in valid {
        state.assign(ti, ii);

        if forward_check(unlocked, idx + 1, state, conflicts, n_items)
            && seq_search(unlocked, idx + 1, state, conflicts, n_items, found, timed_out, deadline)
        {
            return true;
        }

        state.unassign(ti, ii);
    }

    false
}

// ── Helpers ─────────────────────────────────────────────────────────────────

/// Items that don't conflict with any term currently assigned to them.
fn valid_items(ti: usize, state: &State, conflicts: &[Vec<bool>], n_items: usize) -> Vec<usize> {
    (0..n_items)
        .filter(|&ii| !state.item_terms[ii].iter().any(|&oti| conflicts[ti][oti]))
        .collect()
}

/// Forward checking – every remaining unassigned term must still have
/// at least one valid item, otherwise we can prune early.
fn forward_check(
    unlocked: &[usize],
    from: usize,
    state: &State,
    conflicts: &[Vec<bool>],
    n_items: usize,
) -> bool {
    for &ti in &unlocked[from..] {
        if state.assignments[ti].is_some() {
            continue;
        }
        let has_option = (0..n_items).any(|ii| {
            !state.item_terms[ii].iter().any(|&oti| conflicts[ti][oti])
        });
        if !has_option {
            return false;
        }
    }
    true
}
