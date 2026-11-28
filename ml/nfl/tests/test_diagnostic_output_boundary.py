from pathlib import Path

import pandas as pd
import pytest

from picksports_nfl_ml import experiments

assert Path(experiments.__file__).resolve() == (
    Path(__file__).resolve().parents[1] / "src/picksports_nfl_ml/experiments.py"
)


def stub_diagnostic_computation(monkeypatch: pytest.MonkeyPatch) -> list[str]:
    """Keep real schema, dataset, folds and output IO without fitting models."""
    calls: list[str] = []

    def evaluated(*args, **kwargs):
        calls.append("evaluation")
        return {
            "_rows": pd.DataFrame(),
            "win_probability": {"blend": {}},
            "market_deltas": {},
        }

    monkeypatch.setattr(experiments, "_evaluate_fold_with_blend", evaluated)
    monkeypatch.setattr(experiments, "_failure_diagnostics", lambda *a, **k: {})
    monkeypatch.setattr(experiments, "_training_window_comparison", lambda *a, **k: {})
    monkeypatch.setattr(experiments, "_recommendations", lambda *a, **k: [])
    return calls


@pytest.mark.parametrize("protected", ["dataset", "schema"])
@pytest.mark.parametrize("alias", ["direct", "symlink", "hardlink"])
def test_diagnostic_output_preserves_immutable_inputs(
    synthetic_csv: Path,
    schema_path: Path,
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
    protected: str,
    alias: str,
) -> None:
    target = synthetic_csv if protected == "dataset" else schema_path
    before = target.read_bytes()
    output = target
    if alias != "direct":
        output = tmp_path / f"output-{protected}-{alias}.json"
        if alias == "symlink":
            output.symlink_to(target)
        else:
            output.hardlink_to(target)
    calls = stub_diagnostic_computation(monkeypatch)

    with pytest.raises(ValueError, match="must not overwrite"):
        experiments.diagnose(
            synthetic_csv, schema_path, output,
            target_season=2023, training_windows=[None], run_ablations=False,
        )

    assert target.read_bytes() == before
    assert calls == []


@pytest.mark.parametrize("existing", [False, True])
def test_diagnostic_output_allows_distinct_report(
    synthetic_csv: Path,
    schema_path: Path,
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
    existing: bool,
) -> None:
    output = tmp_path / "report.json"
    if existing:
        output.write_text("old report")
    before = {p: p.read_bytes() for p in [synthetic_csv, schema_path]}
    calls = stub_diagnostic_computation(monkeypatch)

    result = experiments.diagnose(
        synthetic_csv, schema_path, output,
        target_season=2023, training_windows=[None], run_ablations=False,
    )

    assert result["report_path"] == str(output)
    assert output.read_text().startswith("{")
    assert {p: p.read_bytes() for p in before} == before
    assert calls == ["evaluation"]
