"""Bounded numeric estimator state. Never deserialize Python objects from artifacts."""
from __future__ import annotations

from dataclasses import dataclass
import json
import hashlib
import re
import math
import os
from pathlib import Path
from typing import Any, NoReturn

import numpy as np
from scipy.special import expit, softmax

FORMAT = "picksports-numeric-state-v1"
TABULAR_FORMAT = "picksports-tabular-numeric-v1"
PERIOD_FORMAT = "picksports-period-numeric-v1"
MAX_COMPONENT_BYTES = 8 * 1024 * 1024
MAX_METADATA_BYTES = 2 * 1024 * 1024
MAX_FEATURES = 512
MAX_THRESHOLDS = 100_000


def _pairs(items: list[tuple[str, Any]]) -> dict[str, Any]:
    result = {}
    for key, value in items:
        if key in result:
            raise ValueError("Duplicate JSON key in numeric artifact")
        result[key] = value
    return result


def _number(text: Any) -> float:
    if type(text) is not str or len(text) > 64:
        raise ValueError("Numeric artifact number is too long")
    value = float(text)
    if not math.isfinite(value):
        raise ValueError("Numeric artifact values must be finite")
    return value


def _integer(text: str) -> int:
    if len(text.lstrip("-")) > 16:
        raise ValueError("Numeric artifact integer is too long")
    return int(text)


def _constant(text: str) -> NoReturn:
    raise ValueError("Nonfinite JSON literal in numeric artifact")


def decode_json(data: bytes, *, max_bytes: int = MAX_COMPONENT_BYTES, max_tokens: int = 1_000_000) -> Any:
    if len(data) > max_bytes:
        raise ValueError("Numeric artifact exceeds its byte budget")
    quoted = escaped = False
    depth = tokens = 0
    for byte in data:
        if quoted:
            if escaped:
                escaped = False
            elif byte == 92:
                escaped = True
            elif byte == 34:
                quoted = False
            continue
        if byte == 34:
            quoted = True
            tokens += 1
        elif byte in (91, 123):
            depth += 1
            tokens += 1
            if depth > 24:
                raise ValueError("Numeric artifact exceeds its nesting budget")
        elif byte in (93, 125):
            depth -= 1
        elif byte in (44, 58):
            tokens += 1
        if tokens > max_tokens:
            raise ValueError("Numeric artifact exceeds its structural budget")
    try:
        return json.loads(data.decode("utf-8"), object_pairs_hook=_pairs,
                          parse_float=_number, parse_int=_integer, parse_constant=_constant)
    except (UnicodeError, json.JSONDecodeError, RecursionError) as error:
        raise ValueError("Invalid numeric artifact JSON") from error


def read_json(path: str | Path, *, max_bytes: int = MAX_COMPONENT_BYTES, max_tokens: int = 1_000_000, expected_sha256: str | None = None, expected_bytes: int | None = None) -> Any:
    if expected_sha256 is not None or expected_bytes is not None:
        if type(expected_sha256) is not str or re.fullmatch(r"[a-f0-9]{64}", expected_sha256) is None or type(expected_bytes) is not int or not 0 <= expected_bytes <= max_bytes:
            raise ValueError("Invalid numeric inventory descriptor")
    path = Path(path)
    if path.is_symlink():
        raise ValueError("Numeric artifacts must not be symlinks")
    before = path.stat()
    if not path.is_file() or before.st_size > max_bytes:
        raise ValueError("Numeric artifact exceeds its byte budget")
    if expected_bytes is not None and before.st_size != expected_bytes:
        raise ValueError("Numeric component size differs from its inventory")
    flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0)
    descriptor = os.open(path, flags)
    with os.fdopen(descriptor, "rb") as handle:
        opened = os.fstat(handle.fileno())
        if (opened.st_dev, opened.st_ino, opened.st_size) != (before.st_dev, before.st_ino, before.st_size):
            raise ValueError("Numeric artifact changed before read")
        data = handle.read(opened.st_size + 1)
        after = os.fstat(handle.fileno())
    current = path.stat()
    if len(data) != before.st_size or (after.st_size, after.st_mtime_ns, after.st_ctime_ns) != (before.st_size, before.st_mtime_ns, before.st_ctime_ns) or (current.st_dev, current.st_ino) != (before.st_dev, before.st_ino):
        raise ValueError("Numeric artifact changed during read")
    if expected_sha256 is not None and hashlib.sha256(data).hexdigest() != expected_sha256:
        raise ValueError("Numeric component hash differs from its inventory")
    return decode_json(data, max_bytes=max_bytes, max_tokens=max_tokens)


def write_json(path: str | Path, state: Any, *, max_bytes: int = MAX_COMPONENT_BYTES, sort_keys: bool = True) -> None:
    data = json.dumps(state, allow_nan=False, sort_keys=sort_keys, separators=(",", ":")).encode("utf-8")
    data += b"\n"
    decode_json(data, max_bytes=max_bytes)
    Path(path).write_bytes(data)


def _keys(state: Any, keys: list[str]) -> None:
    if type(state) is not dict or set(state) != set(keys):
        raise ValueError("Unexpected numeric artifact fields")


def _vector(values: Any, *, length: int | None = None, maximum: int = MAX_THRESHOLDS) -> np.ndarray:
    if type(values) is not list or not 1 <= len(values) <= maximum or (length is not None and len(values) != length):
        raise ValueError("Invalid numeric artifact vector shape")
    if any(type(value) not in (int, float) or not math.isfinite(value) for value in values):
        raise ValueError("Numeric artifact vector must contain finite numbers")
    result = np.asarray(values, dtype=np.float64)
    result.setflags(write=False)
    return result


def _names(names: Any) -> list[str]:
    if type(names) is not list or not 1 <= len(names) <= MAX_FEATURES or any(type(name) is not str or not name or len(name) > 128 for name in names) or len(set(names)) != len(names):
        raise ValueError("Invalid numeric artifact feature names")
    return list(names)


@dataclass(frozen=True)
class NumericPreprocessor:
    feature_names: list[str]
    medians: np.ndarray
    means: np.ndarray
    scales: np.ndarray
    sample_count: int

    def transform(self, values: Any) -> np.ndarray:
        if hasattr(values, "columns") and list(values.columns) != self.feature_names:
            raise ValueError("Numeric preprocessing feature order changed")
        array = np.asarray(values, dtype=np.float64)
        if array.ndim != 2 or array.shape[1] != len(self.feature_names) or np.isinf(array).any():
            raise ValueError("Invalid numeric preprocessing input")
        result = (np.where(np.isnan(array), self.medians, array) - self.means) / self.scales
        if not np.isfinite(result).all():
            raise ValueError("Nonfinite numeric preprocessing result")
        return result


def preprocessor_state(model: Any, feature_names: list[str]) -> dict[str, Any]:
    from sklearn.impute import SimpleImputer
    from sklearn.pipeline import Pipeline
    from sklearn.preprocessing import StandardScaler
    if type(model) is not Pipeline or list(model.named_steps) != ["imputer", "scaler"] or type(model.named_steps["imputer"]) is not SimpleImputer or type(model.named_steps["scaler"]) is not StandardScaler:
        raise ValueError("Only the fixed median/standard-scaling pipeline is supported")
    imputer, scaler = model.named_steps["imputer"], model.named_steps["scaler"]
    if imputer.strategy != "median" or not imputer.keep_empty_features or not scaler.with_mean or not scaler.with_std:
        raise ValueError("Unsupported numeric preprocessing policy")
    result = {"format": FORMAT, "kind": "median_standard_scaler", "feature_names": list(feature_names),
              "medians": imputer.statistics_.tolist(), "means": scaler.mean_.tolist(), "scales": scaler.scale_.tolist(), "sample_count": int(scaler.n_samples_seen_)}
    load_preprocessor(result)
    return result


def load_preprocessor(state: Any) -> NumericPreprocessor:
    _keys(state, ["format", "kind", "feature_names", "medians", "means", "scales", "sample_count"])
    if state["format"] != FORMAT or state["kind"] != "median_standard_scaler":
        raise ValueError("Unsupported numeric preprocessing format")
    names = _names(state["feature_names"])
    arrays = [_vector(state[field], length=len(names), maximum=MAX_FEATURES) for field in ["medians", "means", "scales"]]
    if (arrays[2] <= 0).any():
        raise ValueError("Numeric preprocessing scale must be positive")
    count = state["sample_count"]
    if type(count) is not int or not 1 <= count <= 10_000_000:
        raise ValueError("Invalid fitted preprocessing sample count")
    return NumericPreprocessor(names, *arrays, count)


@dataclass(frozen=True)
class NumericLogistic:
    coefficients: np.ndarray
    intercepts: np.ndarray
    classes_: np.ndarray
    mode: str

    def predict_proba(self, values: Any) -> np.ndarray:
        array = np.asarray(values, dtype=np.float64)
        if array.ndim != 2 or array.shape[1] != self.coefficients.shape[1] or not np.isfinite(array).all():
            raise ValueError("Invalid numeric classifier input")
        with np.errstate(over="ignore", invalid="ignore"):
            scores = array @ self.coefficients.T + self.intercepts
        if not np.isfinite(scores).all():
            raise ValueError("Nonfinite numeric classifier scores")
        if self.mode == "binary_sigmoid":
            probability = expit(scores[:, 0])
            return np.column_stack([1 - probability, probability])
        if self.mode == "multinomial_softmax":
            return softmax(scores, axis=1)
        probabilities = expit(scores)
        total = probabilities.sum(axis=1, keepdims=True)
        if (total == 0).any():
            raise ValueError("Degenerate numeric OVR probability")
        return probabilities / total


def logistic_state(model: Any) -> dict[str, Any]:
    from sklearn.linear_model import LogisticRegression
    if type(model) is not LogisticRegression:
        raise ValueError("Only a fitted fixed logistic classifier is supported")
    if len(model.classes_) == 2 and model.multi_class == "multinomial":
        raise ValueError("Binary multinomial logistic state is not a supported fitted pipeline")
    mode = "binary_sigmoid" if len(model.classes_) == 2 else ("ovr_sigmoid" if model.multi_class == "ovr" else "multinomial_softmax")
    result = {"format": FORMAT, "kind": "logistic_classifier", "classes": model.classes_.tolist(), "mode": mode,
              "coefficients": model.coef_.tolist(), "intercepts": model.intercept_.tolist()}
    load_logistic(result)
    return result


def load_logistic(state: Any) -> NumericLogistic:
    _keys(state, ["format", "kind", "classes", "mode", "coefficients", "intercepts"])
    if state["format"] != FORMAT or state["kind"] != "logistic_classifier":
        raise ValueError("Unsupported numeric logistic format")
    classes = state["classes"]
    if type(classes) is not list or len(classes) not in (2, 3) or any(type(value) is not int or value not in (0, 1, 2) for value in classes) or classes != sorted(set(classes)):
        raise ValueError("Invalid numeric classifier classes")
    mode = state["mode"]
    rows = 1 if len(classes) == 2 else len(classes)
    if mode not in (["binary_sigmoid"] if rows == 1 else ["multinomial_softmax", "ovr_sigmoid"]):
        raise ValueError("Unsupported numeric classifier mode")
    coefficients = state["coefficients"]
    if type(coefficients) is not list or len(coefficients) != rows:
        raise ValueError("Invalid numeric coefficient shape")
    vectors = [_vector(row, maximum=MAX_FEATURES) for row in coefficients]
    if len({len(row) for row in vectors}) != 1:
        raise ValueError("Inconsistent numeric coefficient shape")
    return NumericLogistic(np.vstack(vectors), _vector(state["intercepts"], length=rows, maximum=3), np.asarray(classes, dtype=int), mode)


@dataclass(frozen=True)
class NumericCalibrator:
    method: str
    logistic: NumericLogistic | None = None
    x: np.ndarray | None = None
    y: np.ndarray | None = None

    def predict(self, probabilities: Any) -> np.ndarray:
        values = np.asarray(probabilities, dtype=np.float64)
        if not np.isfinite(values).all():
            raise ValueError("Invalid numeric calibration input")
        if self.method == "platt":
            clipped = np.clip(values, 1e-6, 1 - 1e-6)
            result = self.logistic.predict_proba(np.log(clipped / (1 - clipped)).reshape(-1, 1))[:, 1]
        else:
            result = np.interp(values, self.x, self.y)
        return np.clip(result, 1e-6, 1 - 1e-6)


def calibrator_state(model: Any) -> dict[str, Any]:
    result = {"format": FORMAT, "kind": "probability_calibrator", "method": model.method}
    if model.method == "platt":
        result["logistic"] = logistic_state(model.estimator)
    elif model.method == "isotonic":
        result.update(x=model.estimator.X_thresholds_.tolist(), y=model.estimator.y_thresholds_.tolist())
    else:
        raise ValueError("Unsupported numeric calibration method")
    load_calibrator(result)
    return result


def load_calibrator(state: Any) -> NumericCalibrator:
    if type(state) is not dict or state.get("format") != FORMAT or state.get("kind") != "probability_calibrator":
        raise ValueError("Unsupported numeric calibrator format")
    if state.get("method") == "platt":
        _keys(state, ["format", "kind", "method", "logistic"])
        model = load_logistic(state["logistic"])
        if model.coefficients.shape != (1, 1) or model.classes_.tolist() != [0, 1]:
            raise ValueError("Platt calibration requires one binary logistic feature")
        return NumericCalibrator("platt", logistic=model)
    _keys(state, ["format", "kind", "method", "x", "y"])
    if state["method"] != "isotonic":
        raise ValueError("Unsupported numeric calibrator method")
    x = _vector(state["x"])
    y = _vector(state["y"], length=len(x))
    if (np.diff(x) <= 0).any() or (np.diff(y) < 0).any() or (y < 0).any() or (y > 1).any():
        raise ValueError("Invalid isotonic numeric thresholds")
    return NumericCalibrator("isotonic", x=x, y=y)


def _small_integer(value: Any, minimum: int, maximum: int) -> int:
    if type(value) is str and value.isascii() and value.isdigit() and len(value) <= 10:
        value = int(value)
    if type(value) is not int or not minimum <= value <= maximum:
        raise ValueError("Invalid native tree dimension")
    return value


def validate_tree_state(state: Any, *, expected_kind: str, feature_count: int) -> dict[str, Any]:
    _keys(state, ["format", "kind", "model"])
    kinds = {"xgboost_binary_classifier": "binary:logistic", "xgboost_multiclass_classifier": "multi:softprob", "xgboost_regressor": "reg:squarederror"}
    if state["format"] != FORMAT or state["kind"] != expected_kind or expected_kind not in kinds:
        raise ValueError("Unsupported native tree execution format")
    model = state["model"]
    _keys(model, ["learner", "version"])
    if model["version"] != [2, 1, 3] or any(type(value) is not int for value in model["version"]):
        raise ValueError("Unsupported native tree model version")
    learner = model["learner"]
    _keys(learner, ["attributes", "feature_names", "feature_types", "gradient_booster", "learner_model_param", "objective"])
    if learner["feature_names"] != [] or learner["feature_types"] != []:
        raise ValueError("Native tree feature names must use the bound numeric schema")
    parameters = learner["learner_model_param"]
    _keys(parameters, ["base_score", "boost_from_average", "num_class", "num_feature", "num_target"])
    if _small_integer(parameters["num_feature"], 1, MAX_FEATURES) != feature_count or _small_integer(parameters["num_target"], 1, 1) != 1:
        raise ValueError("Native tree feature count does not match preprocessing")
    classes = _small_integer(parameters["num_class"], 0, 3)
    if classes != (3 if expected_kind == "xgboost_multiclass_classifier" else 0):
        raise ValueError("Native tree class count does not match its role")
    _small_integer(parameters["boost_from_average"], 0, 1)
    if type(parameters["base_score"]) is not str:
        raise ValueError("Invalid native tree base score")
    base_score = _number(parameters["base_score"])
    if expected_kind == "xgboost_binary_classifier" and not 0 < base_score < 1:
        raise ValueError("Binary native tree base score must be strictly between zero and one")
    objective = learner["objective"]
    parameter_key = "softmax_multiclass_param" if classes else "reg_loss_param"
    _keys(objective, ["name", parameter_key])
    if objective["name"] != kinds[expected_kind]:
        raise ValueError("Native tree objective does not match its fixed role")
    if classes:
        _keys(objective[parameter_key], ["num_class"])
        if _small_integer(objective[parameter_key]["num_class"], 3, 3) != classes:
            raise ValueError("Native tree objective class count changed")
    else:
        _keys(objective[parameter_key], ["scale_pos_weight"])
        _number(objective[parameter_key]["scale_pos_weight"])
    booster = learner["gradient_booster"]
    _keys(booster, ["model", "name"])
    if booster["name"] != "gbtree":
        raise ValueError("Only fixed plain numeric trees are supported")
    tree_model = booster["model"]
    _keys(tree_model, ["gbtree_model_param", "iteration_indptr", "tree_info", "trees"])
    tree_parameters = tree_model["gbtree_model_param"]
    _keys(tree_parameters, ["num_parallel_tree", "num_trees"])
    _small_integer(tree_parameters["num_parallel_tree"], 1, 1)
    count = _small_integer(tree_parameters["num_trees"], 1, 4096)
    trees, groups, iterations = tree_model["trees"], tree_model["tree_info"], tree_model["iteration_indptr"]
    if type(trees) is not list or len(trees) != count or type(groups) is not list or any(type(value) is not int for value in groups) or groups != ([0, 1, 2] * (count // 3) if classes else [0] * count):
        raise ValueError("Invalid native tree group shape")
    step = classes or 1
    if count % step or type(iterations) is not list or any(type(value) is not int for value in iterations) or iterations != list(range(0, count + 1, step)):
        raise ValueError("Invalid native tree iteration shape")
    attributes = learner["attributes"]
    if type(attributes) is not dict or set(attributes) - {"best_iteration", "best_score"}:
        raise ValueError("Unsupported native tree attributes")
    if "best_iteration" in attributes:
        _small_integer(attributes["best_iteration"], 0, len(iterations) - 2)
    if "best_score" in attributes:
        _number(attributes["best_score"])
    total_nodes = 0
    vectors = ["base_weights", "default_left", "left_children", "loss_changes", "parents", "right_children", "split_conditions", "split_indices", "split_type", "sum_hessian"]
    for index, tree in enumerate(trees):
        _keys(tree, vectors + ["categories", "categories_nodes", "categories_segments", "categories_sizes", "id", "tree_param"])
        if type(tree["id"]) is not int or tree["id"] != index or any(tree[name] != [] for name in ["categories", "categories_nodes", "categories_segments", "categories_sizes"]):
            raise ValueError("Unsupported categorical or misidentified native tree")
        parameters = tree["tree_param"]
        _keys(parameters, ["num_deleted", "num_feature", "num_nodes", "size_leaf_vector"])
        nodes = _small_integer(parameters["num_nodes"], 1, 100_000)
        total_nodes += nodes
        if total_nodes > 100_000 or _small_integer(parameters["num_feature"], 1, MAX_FEATURES) != feature_count:
            raise ValueError("Native tree nodes exceed their dimension budget")
        _small_integer(parameters["num_deleted"], 0, 0)
        _small_integer(parameters["size_leaf_vector"], 1, 1)
        for name in vectors:
            if type(tree[name]) is not list or len(tree[name]) != nodes:
                raise ValueError("Native tree vector length differs from declared nodes")
        for name in ["base_weights", "loss_changes", "split_conditions", "sum_hessian"]:
            _vector(tree[name], length=nodes)
        if any(type(parent) is not int or not 0 <= parent <= 4294967295 for parent in tree["parents"]):
            raise ValueError("Invalid native tree parent vector")
        incoming = [0] * nodes
        for node, (left, right) in enumerate(zip(tree["left_children"], tree["right_children"])):
            if type(left) is not int or type(right) is not int or (left == -1) != (right == -1):
                raise ValueError("Invalid native tree children")
            if tree["split_type"][node] != 0 or type(tree["split_type"][node]) is not int or type(tree["default_left"][node]) is not int or tree["default_left"][node] not in (0, 1):
                raise ValueError("Unsupported native tree split")
            feature = tree["split_indices"][node]
            if type(feature) is not int or not 0 <= feature < feature_count:
                raise ValueError("Native tree split feature exceeds its bound schema")
            parent = tree["parents"][node]
            if type(parent) is not int or not 0 <= parent <= 4294967295 or (node == 0 and parent != 2147483647):
                raise ValueError("Invalid native tree parent")
            if left != -1:
                for child in (left, right):
                    if not 0 <= child < nodes or child == node or (tree["parents"][child] & 2147483647) != node:
                        raise ValueError("Invalid native tree child ownership")
                    incoming[child] += 1
        if incoming != [0] + [1] * (nodes - 1):
            raise ValueError("Native tree must have one root and unique child ownership")
        visited = set()
        pending = [(0, 0)]
        while pending:
            node, depth = pending.pop()
            if node in visited or depth > 64:
                raise ValueError("Native tree contains a cycle or excessive depth")
            visited.add(node)
            if tree["left_children"][node] != -1:
                pending.extend([(tree["left_children"][node], depth + 1), (tree["right_children"][node], depth + 1)])
        if len(visited) != nodes:
            raise ValueError("Native tree contains unreachable nodes")
    return model


def tree_state(model: Any, *, kind: str, feature_count: int) -> dict[str, Any]:
    from xgboost import XGBClassifier, XGBRegressor
    if type(model) is not (XGBRegressor if kind == "xgboost_regressor" else XGBClassifier):
        raise ValueError("Unsupported native tree estimator")
    result = {"format": FORMAT, "kind": kind, "model": decode_json(bytes(model.get_booster().save_raw(raw_format="json")))}
    validate_tree_state(result, expected_kind=kind, feature_count=feature_count)
    return result


def load_tree(state: Any, *, expected_kind: str, feature_count: int) -> Any:
    from xgboost import XGBClassifier, XGBRegressor
    model = validate_tree_state(state, expected_kind=expected_kind, feature_count=feature_count)
    estimator = XGBRegressor(n_jobs=1) if expected_kind == "xgboost_regressor" else XGBClassifier(n_jobs=1)
    estimator.load_model(bytearray(json.dumps(model, allow_nan=False, separators=(",", ":")).encode("utf-8")))
    return estimator


@dataclass(frozen=True)
class NumericMulticlassCalibrator:
    method: str
    calibrators: dict[int, NumericCalibrator]
    probability_floor: float

    def predict(self, probabilities: Any) -> np.ndarray:
        raw = np.asarray(probabilities, dtype=float)
        if raw.ndim != 2 or raw.shape[1] != 3 or not np.isfinite(raw).all():
            raise ValueError("Invalid multiclass numeric calibration input")
        result = np.column_stack([self.calibrators[label].predict(raw[:, label]) for label in (0, 1, 2)])
        result = np.clip(result, self.probability_floor, 1 - self.probability_floor)
        return result / result.sum(axis=1, keepdims=True)


def multiclass_calibrator_state(model: Any) -> dict[str, Any]:
    state = {"format": FORMAT, "kind": "multiclass_calibrator", "method": model.method,
             "probability_floor": model.probability_floor,
             "calibrators": {str(label): calibrator_state(model.calibrators[label]) for label in (0, 1, 2)}}
    load_multiclass_calibrator(state)
    return state


def load_multiclass_calibrator(state: Any) -> NumericMulticlassCalibrator:
    _keys(state, ["format", "kind", "method", "probability_floor", "calibrators"])
    if state["format"] != FORMAT or state["kind"] != "multiclass_calibrator" or state["method"] not in ("platt", "isotonic"):
        raise ValueError("Unsupported multiclass numeric calibration format")
    floor = state["probability_floor"]
    if type(floor) not in (int, float) or not math.isfinite(floor) or not 0 < floor < .5:
        raise ValueError("Invalid multiclass numeric probability floor")
    _keys(state["calibrators"], ["0", "1", "2"])
    calibrators = {label: load_calibrator(state["calibrators"][str(label)]) for label in (0, 1, 2)}
    if any(model.method != state["method"] for model in calibrators.values()):
        raise ValueError("Multiclass calibration methods disagree")
    return NumericMulticlassCalibrator(state["method"], calibrators, float(floor))


def period_bundle_state(bundle: dict[str, Any]) -> dict[str, Any]:
    features = _names(bundle["features"])
    markets = {}
    for market in ("first_3_moneyline", "first_5_moneyline"):
        source = bundle["markets"][market]
        markets[market] = {
            "preprocessor": preprocessor_state(source["preprocessor"], features),
            "models": {"logistic_regression": logistic_state(source["models"]["logistic_regression"]),
                       "xgboost": tree_state(source["models"]["xgboost"], kind="xgboost_multiclass_classifier", feature_count=len(features))},
            "calibrators": {name: {method: multiclass_calibrator_state(source["calibrators"][name][method]) for method in ("platt", "isotonic")} for name in ("logistic_regression", "xgboost")},
            "selected_model": source["selected_model"], "selected_calibration": source["selected_calibration"],
        }
    return {"format": PERIOD_FORMAT, "manifest": bundle["manifest"], "features": features, "markets": markets}


def load_period_bundle(state: Any) -> dict[str, Any]:
    if type(state) is not dict or state.get("format") != PERIOD_FORMAT:
        raise ValueError("Unsupported period execution format; numeric artifact version is required")
    _keys(state, ["format", "manifest", "features", "markets"])
    manifest = state["manifest"]
    if type(manifest) is not dict or manifest.get("execution_format") != PERIOD_FORMAT:
        raise ValueError("Unsupported period execution format in manifest")
    for field in ["model_run_id", "artifact_id", "dataset_hash", "model_version"]:
        if type(manifest.get(field)) is not str or not 1 <= len(manifest[field]) <= 128:
            raise ValueError("Invalid numeric period lineage")
    features = _names(state["features"])
    _keys(state["markets"], ["first_3_moneyline", "first_5_moneyline"])
    markets = {}
    for market, source in state["markets"].items():
        _keys(source, ["preprocessor", "models", "calibrators", "selected_model", "selected_calibration"])
        _keys(source["models"], ["logistic_regression", "xgboost"])
        _keys(source["calibrators"], ["logistic_regression", "xgboost"])
        _keys(source["selected_calibration"], ["logistic_regression", "xgboost"])
        if source["selected_model"] not in ("logistic_regression", "xgboost"):
            raise ValueError("Unsupported numeric period classifier selection")
        preprocessor = load_preprocessor(source["preprocessor"])
        logistic = load_logistic(source["models"]["logistic_regression"])
        if preprocessor.feature_names != features or logistic.coefficients.shape[1] != len(features):
            raise ValueError("Numeric period features differ from fitted state")
        if logistic.classes_.tolist() != [0, 1, 2]:
            raise ValueError("Numeric period classifier role requires home/tie/away classes")
        calibrators = {}
        for name in ("logistic_regression", "xgboost"):
            _keys(source["calibrators"][name], ["platt", "isotonic"])
            if source["selected_calibration"][name] not in ("platt", "isotonic"):
                raise ValueError("Unsupported numeric period calibrator selection")
            calibrators[name] = {method: load_multiclass_calibrator(source["calibrators"][name][method]) for method in ("platt", "isotonic")}
            if any(model.method != method for method, model in calibrators[name].items()):
                raise ValueError("Numeric period calibration role differs from its declared method")
        validate_tree_state(source["models"]["xgboost"], expected_kind="xgboost_multiclass_classifier", feature_count=len(features))
        markets[market] = {"preprocessor": preprocessor, "models": {"logistic_regression": logistic},
                           "calibrators": calibrators, "selected_model": source["selected_model"], "selected_calibration": source["selected_calibration"]}
    # Every component/selection is admitted before any native tree constructor/load.
    for market in markets:
        markets[market]["models"]["xgboost"] = load_tree(state["markets"][market]["models"]["xgboost"], expected_kind="xgboost_multiclass_classifier", feature_count=len(features))
    return {"manifest": manifest, "features": features, "markets": markets}
