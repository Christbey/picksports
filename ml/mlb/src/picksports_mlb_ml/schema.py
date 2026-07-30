from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Any

import pandas as pd
import yaml

from picksports_mlb_ml.hashing import sha256_json


class SchemaError(ValueError):
    """Raised when a dataset violates the declared feature contract."""


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
        return list(self.raw["features"])

    @property
    def target_columns(self) -> dict[str, str]:
        return {name: str(column) for name, column in self.raw["targets"].items()}

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

    @property
    def anchor_columns(self) -> list[str]:
        return [
            str(column)
            for column in self.raw["training"].get("blend", {}).get(
                "anchor_columns", []
            )
        ]

    def validate_training_frame(self, frame: pd.DataFrame) -> pd.DataFrame:
        validated = self._apply_aliases(frame.copy())
        provenance = set(self.raw["dataset"]["required_provenance_columns"])
        required_features = {
            name
            for name, spec in self.raw["features"].items()
            if not bool(spec.get("nullable", False))
        }
        required = provenance | required_features | set(self.target_columns.values())
        missing = sorted(required.difference(validated.columns))
        if missing:
            raise SchemaError(f"Dataset is missing required columns: {', '.join(missing)}")

        for feature, spec in self.raw["features"].items():
            if feature not in validated:
                validated[feature] = pd.NA
            validated[feature] = pd.to_numeric(validated[feature], errors="coerce")
            if not bool(spec.get("nullable", False)) and validated[feature].isna().any():
                raise SchemaError(
                    f"Non-nullable feature '{feature}' contains missing values."
                )

        if self.raw["dataset"].get("reject_undeclared_feature_columns", False):
            ignored = set(self.raw["dataset"].get("ignored_feature_columns", []))
            undeclared = sorted(
                column
                for column in validated
                if column.startswith("feature_")
                and column not in self.feature_names
                and column not in ignored
            )
            if undeclared:
                raise SchemaError(
                    "Dataset contains undeclared feature columns: "
                    + ", ".join(undeclared)
                )

        validated[self.time_column] = pd.to_datetime(
            validated[self.time_column], utc=True, errors="raise"
        )
        validated["features_available_at"] = pd.to_datetime(
            validated["features_available_at"], utc=True, errors="raise"
        )
        validated[self.season_column] = pd.to_numeric(
            validated[self.season_column], errors="raise"
        ).astype("int64")

        for target in self.target_columns.values():
            validated[target] = pd.to_numeric(validated[target], errors="coerce")
            if validated[target].isna().any():
                raise SchemaError(f"Target '{target}' contains missing values.")
        if not validated[self.target_columns["home_win"]].isin([0, 1]).all():
            raise SchemaError("Home-win target must contain only 0 or 1.")

        pregame = (
            validated["pregame_safe"]
            .astype(str)
            .str.strip()
            .str.lower()
            .map({"1": True, "0": False, "true": True, "false": False})
        )
        if pregame.isna().any() or not pregame.all():
            raise SchemaError("Every training row must be marked pregame safe.")
        validated["pregame_safe"] = pregame

        allowed_statuses = set(
            self.raw["dataset"].get(
                "allowed_availability_statuses",
                ["observed_pregame", "verified_reconstruction"],
            )
        )
        invalid_statuses = sorted(
            set(validated["availability_status"].astype(str)) - allowed_statuses
        )
        if invalid_statuses:
            raise SchemaError(
                "Untrusted availability statuses: " + ", ".join(invalid_statuses)
            )
        if (validated["features_available_at"] > validated[self.time_column]).any():
            raise SchemaError("Features must be available no later than game start.")
        if validated[self.id_column].duplicated().any():
            raise SchemaError("Training data must contain one stable row per game.")

        return validated.sort_values(
            [self.time_column, self.id_column], kind="stable"
        ).reset_index(drop=True)

    def validate_inference_frame(self, frame: pd.DataFrame) -> pd.DataFrame:
        validated = self._apply_aliases(frame.copy())
        missing_required = [
            name
            for name, spec in self.raw["features"].items()
            if name not in validated and not bool(spec.get("nullable", False))
        ]
        if missing_required:
            raise SchemaError(
                "Inference input is missing required features: "
                + ", ".join(missing_required)
            )
        for feature, spec in self.raw["features"].items():
            if feature not in validated:
                validated[feature] = pd.NA
            validated[feature] = pd.to_numeric(validated[feature], errors="coerce")
            if not bool(spec.get("nullable", False)) and validated[feature].isna().any():
                raise SchemaError(
                    f"Non-nullable feature '{feature}' contains missing values."
                )
        return validated

    def _apply_aliases(self, frame: pd.DataFrame) -> pd.DataFrame:
        for canonical, spec in self.raw["features"].items():
            for alias in spec.get("aliases", []):
                if alias not in frame:
                    continue
                if canonical not in frame:
                    frame[canonical] = frame[alias]
                else:
                    frame[canonical] = frame[canonical].fillna(frame[alias])
        return frame
