from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Any

import pandas as pd
import yaml

from picksports_nfl_ml.hashing import sha256_json


class SchemaError(ValueError):
    """Raised when an input dataset violates the declared feature contract."""


@dataclass(frozen=True)
class FeatureSchema:
    path: Path
    raw: dict[str, Any]

    @classmethod
    def load(cls, path: str | Path) -> "FeatureSchema":
        resolved = Path(path).expanduser().resolve()
        with resolved.open("r", encoding="utf-8") as handle:
            raw = yaml.safe_load(handle)
        if not isinstance(raw, dict):
            raise SchemaError("Feature schema must be a YAML mapping.")
        for section in ("schema_version", "dataset", "features", "targets", "training"):
            if section not in raw:
                raise SchemaError(f"Feature schema is missing '{section}'.")
        return cls(path=resolved, raw=raw)

    @property
    def version(self) -> str:
        return str(self.raw["schema_version"])

    @property
    def hash(self) -> str:
        return sha256_json(self.raw)

    @property
    def feature_names(self) -> list[str]:
        return list(self.raw["features"].keys())

    @property
    def target_columns(self) -> dict[str, str]:
        return dict(self.raw["targets"])

    @property
    def training(self) -> dict[str, Any]:
        return dict(self.raw["training"])

    @property
    def time_column(self) -> str:
        return str(self.raw["dataset"]["time_column"])

    @property
    def season_column(self) -> str:
        return str(self.raw["dataset"]["season_column"])

    @property
    def id_column(self) -> str:
        return str(self.raw["dataset"]["id_column"])

    def validate_training_frame(self, frame: pd.DataFrame) -> pd.DataFrame:
        required = set(self.raw["dataset"]["required_provenance_columns"])
        required.update(self.feature_names)
        required.update(self.target_columns.values())
        missing = sorted(required.difference(frame.columns))
        if missing:
            raise SchemaError(f"Dataset is missing required columns: {', '.join(missing)}")

        if self.raw["dataset"].get("reject_undeclared_feature_columns", True):
            ignored = set(self.raw["dataset"].get("ignored_feature_columns", []))
            provenance = set(self.raw["dataset"]["required_provenance_columns"])
            undeclared = sorted(
                column
                for column in frame.columns
                if column.startswith("feature_")
                and column not in self.feature_names
                and column not in ignored
                and column not in provenance
            )
            if undeclared:
                raise SchemaError(
                    "Dataset contains undeclared feature columns: " + ", ".join(undeclared)
                )

        validated = frame.copy()
        validated[self.time_column] = pd.to_datetime(
            validated[self.time_column], utc=True, errors="raise"
        )
        validated["features_available_at"] = pd.to_datetime(
            validated["features_available_at"], utc=True, errors="raise"
        )
        validated[self.season_column] = pd.to_numeric(
            validated[self.season_column], errors="raise"
        ).astype("int64")

        for feature, spec in self.raw["features"].items():
            validated[feature] = pd.to_numeric(validated[feature], errors="coerce")
            if not bool(spec.get("nullable", False)) and validated[feature].isna().any():
                raise SchemaError(f"Non-nullable feature '{feature}' contains missing values.")

        for target in self.target_columns.values():
            validated[target] = pd.to_numeric(validated[target], errors="coerce")
            if validated[target].isna().any():
                raise SchemaError(f"Target '{target}' contains missing values.")

        home_win = self.target_columns["home_win"]
        invalid_targets = ~validated[home_win].isin([0, 1])
        if invalid_targets.any():
            raise SchemaError("Home-win target must contain only 0 or 1.")

        pregame_safe = (
            validated["pregame_safe"]
            .astype(str)
            .str.strip()
            .str.lower()
            .map({"1": True, "0": False, "true": True, "false": False})
        )
        if pregame_safe.isna().any() or not pregame_safe.all():
            raise SchemaError("Every training row must be explicitly pregame safe.")
        validated["pregame_safe"] = pregame_safe

        if (validated["features_available_at"] > validated[self.time_column]).any():
            raise SchemaError("Features were observed after game start.")
        if validated[self.id_column].duplicated().any():
            raise SchemaError("Dataset must contain exactly one row per game_id.")
        if validated["feature_hash"].isna().any() or validated["target_hash"].isna().any():
            raise SchemaError("Feature and target hashes are required for every row.")

        validated = validated.sort_values(
            [self.time_column, self.id_column], kind="stable"
        ).reset_index(drop=True)
        return validated

    def validate_inference_frame(self, frame: pd.DataFrame) -> pd.DataFrame:
        validated = frame.copy()
        missing = sorted(set(self.feature_names).difference(validated.columns))
        required_missing = [
            feature
            for feature in missing
            if not bool(self.raw["features"][feature].get("nullable", False))
        ]
        if required_missing:
            raise SchemaError(
                "Inference row is missing required features: "
                + ", ".join(required_missing)
            )
        for feature in missing:
            validated[feature] = float("nan")
        for feature in self.feature_names:
            validated[feature] = pd.to_numeric(validated[feature], errors="coerce")
            spec = self.raw["features"][feature]
            if not bool(spec.get("nullable", False)) and validated[feature].isna().any():
                raise SchemaError(f"Non-nullable feature '{feature}' contains missing values.")
        return validated
