//! Case similarity for the AI module's experience retrieval.
//!
//! This is the CBRKit driver's own measure (`docker/cognition/cbrkit/retriever.py` in the AI module),
//! ported so the module can score a casebase in-process instead of over HTTP. It must stay equal to
//! that file: identity features (`object_id`, `planet_id`) and text compare by equality, every other
//! feature is numeric and scores `1 - |a - b| / max(1, |a|, |b|)`, and the weighted mean runs over the
//! features the query states. A case value that is unknown scores 0 but still counts toward the weight.

use serde_json::{Map, Value};
use std::ffi::{CStr, CString};
use std::os::raw::c_char;

const UNKNOWN_FEATURE_SCORE: f64 = 0.0;

fn weight(key: &str) -> f64 {
    match key {
        "object_id" => 2.0,
        "target_level" => 1.0,
        "planet_id" => 0.5,
        _ => 1.0,
    }
}

fn is_identity(key: &str) -> bool {
    key == "object_id" || key == "planet_id"
}

/// Python's `float(value)` for the values a feature can hold; `None` when it has no number.
fn as_number(value: &Value) -> Option<f64> {
    match value {
        Value::Number(number) => number.as_f64(),
        Value::Bool(flag) => Some(if *flag { 1.0 } else { 0.0 }),
        _ => None,
    }
}

/// Python's `==` between two feature values: numbers compare by value (1 == 1.0), the rest by content.
fn same(case_value: &Value, query_value: &Value) -> bool {
    match (as_number(case_value), as_number(query_value)) {
        (Some(left), Some(right)) => left == right,
        _ => case_value == query_value,
    }
}

/// A feature set as a map. PHP encodes an empty array as `[]`, which is an empty feature set, not a mistake.
fn features(value: &Value) -> Option<Map<String, Value>> {
    match value {
        Value::Object(map) => Some(map.clone()),
        Value::Array(items) if items.is_empty() => Some(Map::new()),
        _ => None,
    }
}

fn feature_similarity(key: &str, case_value: &Value, query_value: &Value) -> f64 {
    if case_value.is_null() {
        return UNKNOWN_FEATURE_SCORE;
    }

    if is_identity(key) || case_value.is_string() || query_value.is_string() {
        return if same(case_value, query_value) { 1.0 } else { 0.0 };
    }

    match (as_number(case_value), as_number(query_value)) {
        (Some(case_number), Some(query_number)) => {
            let scale = 1.0_f64.max(case_number.abs()).max(query_number.abs());

            (1.0 - (case_number - query_number).abs() / scale).max(0.0)
        }
        _ => UNKNOWN_FEATURE_SCORE,
    }
}

/// Weighted similarity over the features the query states and the case holds a key for.
pub fn similarity(case: &Map<String, Value>, query: &Map<String, Value>) -> f64 {
    let mut weighted = 0.0;
    let mut total_weight = 0.0;

    for (key, query_value) in query {
        if query_value.is_null() {
            continue;
        }
        let Some(case_value) = case.get(key) else {
            continue;
        };

        let w = weight(key);
        weighted += w * feature_similarity(key, case_value, query_value);
        total_weight += w;
    }

    if total_weight == 0.0 {
        0.0
    } else {
        weighted / total_weight
    }
}

/// `{"casebase": {id: features}, "queries": {name: features}}` to `{name: {id: similarity}}`.
/// Returns `None` when the input is not that shape, so the caller can use its fallback path.
fn rank(input: &str) -> Option<String> {
    let parsed: Value = serde_json::from_str(input).ok()?;
    let casebase = parsed.get("casebase")?.as_object()?;
    let queries = parsed.get("queries")?.as_object()?;

    let mut answers = Map::new();

    for (name, query) in queries {
        let query = features(query)?;
        let mut scores = Map::new();

        for (id, case) in casebase {
            scores.insert(id.clone(), Value::from(similarity(&features(case)?, &query)));
        }

        answers.insert(name.clone(), Value::Object(scores));
    }

    serde_json::to_string(&Value::Object(answers)).ok()
}

/// Called from the AI module's `RustCaseSimilarity`. The result is freed with `free_battle_result`,
/// which releases any string this library hands out. A null pointer means the input was unusable.
#[no_mangle]
pub extern "C" fn rank_case_similarities(input_json: *const c_char) -> *mut c_char {
    if input_json.is_null() {
        return std::ptr::null_mut();
    }

    let Ok(input) = unsafe { CStr::from_ptr(input_json) }.to_str() else {
        return std::ptr::null_mut();
    };

    match rank(input).and_then(|json| CString::new(json).ok()) {
        Some(c_string) => c_string.into_raw(),
        None => std::ptr::null_mut(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn map(value: Value) -> Map<String, Value> {
        value.as_object().cloned().unwrap()
    }

    #[test]
    fn identity_matches_only_on_equality() {
        let query = map(json!({"object_id": 3}));

        assert_eq!(similarity(&map(json!({"object_id": 3})), &query), 1.0);
        assert_eq!(similarity(&map(json!({"object_id": 4})), &query), 0.0);
        assert_eq!(similarity(&map(json!({"object_id": 3.0})), &query), 1.0);
    }

    #[test]
    fn numeric_feature_scores_by_relative_distance() {
        let query = map(json!({"target_level": 10}));

        assert_eq!(similarity(&map(json!({"target_level": 5})), &query), 0.5);
        assert_eq!(similarity(&map(json!({"target_level": 10})), &query), 1.0);
    }

    #[test]
    fn weighted_mean_counts_an_unknown_case_value_as_zero() {
        let query = map(json!({"object_id": 3, "target_level": 4}));
        let case = map(json!({"object_id": 3, "target_level": null}));

        // (2 * 1 + 1 * 0) / 3
        assert!((similarity(&case, &query) - 2.0 / 3.0).abs() < 1e-12);
    }

    #[test]
    fn features_the_query_does_not_state_or_the_case_lacks_are_skipped() {
        let query = map(json!({"object_id": 3, "planet_id": null, "extra": 1}));
        let case = map(json!({"object_id": 3, "planet_id": 9}));

        assert_eq!(similarity(&case, &query), 1.0);
        assert_eq!(similarity(&case, &map(json!({}))), 0.0);
    }

    #[test]
    fn text_compares_by_equality() {
        let query = map(json!({"resource": "metal"}));

        assert_eq!(similarity(&map(json!({"resource": "metal"})), &query), 1.0);
        assert_eq!(similarity(&map(json!({"resource": "crystal"})), &query), 0.0);
    }

    #[test]
    fn rank_accepts_an_empty_feature_set_encoded_as_a_php_array() {
        let input = r#"{"casebase": {"1": [], "2": {"object_id": 3}}, "queries": {"q0": {"object_id": 3}, "q1": []}}"#;
        let output: Value = serde_json::from_str(&rank(input).unwrap()).unwrap();

        assert_eq!(output["q0"]["1"], 0.0);
        assert_eq!(output["q0"]["2"], 1.0);
        assert_eq!(output["q1"]["2"], 0.0);
    }

    #[test]
    fn rank_answers_every_case_for_every_query() {
        let input = json!({"casebase": {"1": {"object_id": 3}, "2": {"object_id": 4}}, "queries": {"q0": {"object_id": 3}}}).to_string();
        let output: Value = serde_json::from_str(&rank(&input).unwrap()).unwrap();

        assert_eq!(output["q0"]["1"], 1.0);
        assert_eq!(output["q0"]["2"], 0.0);
        assert!(rank("not json").is_none());
    }
}
