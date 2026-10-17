"""Read-only postgame diagnostics from immutable accepted-source exports."""
import argparse
import hashlib
import json
from pathlib import Path
from scoring import predict, tilt
from efficiency import postgame_expectancy


def report(artifact_path, manifest_path):
    artifact = json.loads(Path(artifact_path).read_text())
    manifest = json.loads(Path(manifest_path).read_text())
    content = Path(manifest["data_path"]).read_bytes()
    if hashlib.sha256(content).hexdigest() != manifest["sha256"]:
        raise ValueError("Dataset hash mismatch")
    results, excluded = [], []
    for line in content.splitlines():
        row = json.loads(line)
        try:
            results.append(postgame_expectancy(artifact, row, predict, tilt))
        except ValueError as error:
            excluded.append({"game_id": row["game_id"], "reason": str(error)})
    return {"artifact_sha256": hashlib.sha256(Path(artifact_path).read_bytes()).hexdigest(),
            "dataset_sha256": manifest["sha256"], "evidence_mode": artifact["evidence_mode"],
            "results": results, "excluded": excluded, "pregame_forecast": False}


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--artifact", required=True)
    parser.add_argument("--manifest", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    try:
        payload = report(args.artifact, args.manifest)
        with open(args.output, "x") as output:
            json.dump(payload, output, sort_keys=True, allow_nan=False)
        print(json.dumps({"evaluated_games": len(payload["results"]), "excluded_games": len(payload["excluded"])}))
    except (ValueError, KeyError, OSError) as error:
        parser.exit(2, str(error)+"\n")
