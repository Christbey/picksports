"""Build a generator-compatible OpenAPI 3.0 copy; never modify the API contract.

Generator 7.14 mishandles JSON Schema null unions, raw report unions and duplicate
HTTP bearer helpers. SDKs share the same Authorization transport for bearer
credentials. Native OAuth acquisition remains independently described.
"""
import copy
import json
import pathlib
import sys


def convert(value):
    if isinstance(value, list):
        return [convert(item) for item in value]
    if not isinstance(value, dict):
        return value
    value = copy.deepcopy(value)
    for requirement in value.get("security", []):
        if "developerApiCredential" in requirement:
            requirement["sanctumBearer"] = requirement.pop("developerApiCredential")
    properties = value.get("properties")
    if isinstance(properties, dict):
        value["properties"] = {
            key: {"not": {}} if item is False else {} if item is True else item
            for key, item in properties.items()
        }
    elif properties == []:
        value["properties"] = {}
    if value.get("type") == "array" and "items" not in value:
        value["items"] = {}
    # Conditional field requirements are enforced by the server. They have no
    # OpenAPI 3.0 SDK equivalent; the published 3.1 contract retains them.
    value.pop("dependentRequired", None)
    if "const" in value:
        value["enum"] = [value.pop("const")]
    types = value.get("type")
    if isinstance(types, list):
        value.pop("type")
        remaining = [kind for kind in types if kind != "null"]
        if "null" in types:
            value["nullable"] = True
        if len(remaining) == 1:
            value["type"] = remaining[0]
        else:
            value["oneOf"] = [{"type": kind} for kind in remaining]
    for key in ["oneOf", "anyOf"]:
        if {"type": "null"} in value.get(key, []):
            remaining = [item for item in value.pop(key) if item != {"type": "null"}]
            if len(remaining) == 1:
                value.update({"allOf": remaining} if "$ref" in remaining[0] else remaining[0])
            else:
                value[key] = remaining
            value["nullable"] = True
    return {key: convert(item) for key, item in value.items()}


def prepare(spec):
    result = convert(spec)
    result["openapi"] = "3.0.3"
    schemes = result["components"]["securitySchemes"]
    expected = {"type": "http", "scheme": "bearer"}
    for name in ["sanctumBearer", "developerApiCredential"]:
        if any(schemes[name].get(key) != item for key, item in expected.items()):
            raise ValueError("SDK bearer transports changed; review normalization")
    del schemes["developerApiCredential"]
    # These observational endpoints intentionally accept opaque raw JSON.
    result["components"]["schemas"]["SecurityReportRequest"] = {
        "description": "Raw JSON object or array report; transported without conversion."
    }
    return result


if __name__ == "__main__":
    source, destination = map(pathlib.Path, sys.argv[1:3])
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text(json.dumps(prepare(json.loads(source.read_text())), indent=2) + "\n")
