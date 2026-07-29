from __future__ import annotations

from dataclasses import dataclass
from typing import Any

import numpy as np
import pandas as pd
from sklearn.impute import SimpleImputer
from sklearn.linear_model import LogisticRegression
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import StandardScaler
from xgboost import XGBClassifier, XGBRegressor

from picksports_nfl_ml.schema import FeatureSchema


@dataclass
class ModelSet:
    preprocessor: Pipeline
    logistic_classifier: LogisticRegression
    xgboost_classifier: XGBClassifier
    margin_regressor: XGBRegressor
    total_regressor: XGBRegressor


def fit_model_set(
    train: pd.DataFrame,
    schema: FeatureSchema,
    seed: int,
    tuned_parameters: dict[str, dict[str, Any]] | None = None,
) -> ModelSet:
    features = train[schema.feature_names]
    targets = schema.target_columns
    config = schema.training
    preprocessor = build_preprocessor()
    transformed = preprocessor.fit_transform(features)
    tuned_parameters = tuned_parameters or {}

    logistic_config = dict(config["logistic_regression"])
    logistic = LogisticRegression(
        random_state=seed,
        solver="lbfgs",
        **logistic_config,
    )
    logistic.fit(transformed, train[targets["home_win"]].astype(int))

    classifier = XGBClassifier(
        objective="binary:logistic",
        eval_metric="logloss",
        tree_method="hist",
        random_state=seed,
        n_jobs=1,
        **{
            **dict(config["xgboost_classifier"]),
            **tuned_parameters.get("xgboost_classifier", {}),
        },
    )
    classifier.fit(transformed, train[targets["home_win"]].astype(int))

    regressor_config: dict[str, Any] = dict(config["xgboost_regressor"])
    margin_regressor = XGBRegressor(
        objective="reg:squarederror",
        eval_metric="rmse",
        tree_method="hist",
        random_state=seed,
        n_jobs=1,
        **{
            **regressor_config,
            **tuned_parameters.get("xgboost_home_margin", {}),
        },
    )
    margin_regressor.fit(transformed, train[targets["home_margin"]].astype(float))

    total_regressor = XGBRegressor(
        objective="reg:squarederror",
        eval_metric="rmse",
        tree_method="hist",
        random_state=seed + 1,
        n_jobs=1,
        **{
            **regressor_config,
            **tuned_parameters.get("xgboost_total_points", {}),
        },
    )
    total_regressor.fit(transformed, train[targets["total_points"]].astype(float))

    return ModelSet(
        preprocessor=preprocessor,
        logistic_classifier=logistic,
        xgboost_classifier=classifier,
        margin_regressor=margin_regressor,
        total_regressor=total_regressor,
    )


def build_preprocessor() -> Pipeline:
    return Pipeline(
        steps=[
            (
                "imputer",
                SimpleImputer(strategy="median", keep_empty_features=True),
            ),
            ("scaler", StandardScaler()),
        ]
    )


def transformed_features(
    models: ModelSet,
    frame: pd.DataFrame,
    schema: FeatureSchema,
) -> np.ndarray:
    return np.asarray(
        models.preprocessor.transform(frame[schema.feature_names]),
        dtype=float,
    )


def classifier_probabilities(
    models: ModelSet,
    model_name: str,
    transformed: np.ndarray,
) -> np.ndarray:
    if model_name == "logistic_regression":
        estimator = models.logistic_classifier
    elif model_name == "xgboost":
        estimator = models.xgboost_classifier
    else:
        raise ValueError(f"Unknown classifier: {model_name}")
    return np.asarray(estimator.predict_proba(transformed)[:, 1], dtype=float)
