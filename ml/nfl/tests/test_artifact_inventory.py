import pytest

from picksports_nfl_ml.artifacts import REQUIRED_ARTIFACTS, verify_artifact_inventory
from picksports_nfl_ml.hashing import sha256_file
from picksports_nfl_ml.inference import InferenceBundle
from picksports_nfl_ml.numeric_artifacts import TABULAR_FORMAT


def inventory(tmp_path):
    artifacts = {}
    for relative in REQUIRED_ARTIFACTS:
        path = tmp_path / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(b"synthetic, never deserialized")
        artifacts[relative] = {"bytes": path.stat().st_size, "sha256": sha256_file(path)}
    return {"execution_format": TABULAR_FORMAT, "artifacts": artifacts}


def test_regular_inventory_remains_valid(tmp_path):
    verify_artifact_inventory(tmp_path, inventory(tmp_path))


def test_symlinked_serialized_artifact_is_rejected_before_loading(tmp_path):
    manifest = inventory(tmp_path)
    outside = tmp_path.parent / (tmp_path.name + "-outside.joblib")
    outside.write_bytes(b"synthetic, never deserialized")
    target = tmp_path / "preprocessor.json"
    target.unlink()
    target.symlink_to(outside)
    with pytest.raises(ValueError, match="symlinks"):
        verify_artifact_inventory(tmp_path, manifest)


@pytest.mark.parametrize("entry", ["../outside", "/absolute", "models/../outside", "models//extra", "models/./extra", "models\\extra"])
def test_inventory_paths_are_canonical(tmp_path, entry):
    manifest = inventory(tmp_path)
    manifest["artifacts"][entry] = {"bytes": 0, "sha256": "0" * 64}
    with pytest.raises(ValueError, match="canonical relative"):
        verify_artifact_inventory(tmp_path, manifest)


@pytest.mark.parametrize("descriptor", [{}, {"bytes": True, "sha256": "0" * 64}, {"bytes": -1, "sha256": "0" * 64}, {"bytes": 0, "sha256": "invalid"}])
def test_invalid_descriptor_fails_closed(tmp_path, descriptor):
    manifest = inventory(tmp_path)
    manifest["artifacts"]["preprocessor.json"] = descriptor
    with pytest.raises(ValueError, match="descriptors"):
        verify_artifact_inventory(tmp_path, manifest)


@pytest.mark.parametrize("manifest_link", [False, True])
def test_loader_rejects_symlink_before_manifest_parse(tmp_path, manifest_link):
    outside = tmp_path / "outside"
    outside.mkdir()
    (outside / "manifest.json").write_text("not JSON")
    if manifest_link:
        root = tmp_path / "run"
        root.mkdir()
        (root / "manifest.json").symlink_to(outside / "manifest.json")
    else:
        root = tmp_path / "run"
        root.symlink_to(outside, target_is_directory=True)
    with pytest.raises(ValueError, match="symlinks"):
        InferenceBundle.load(root)
