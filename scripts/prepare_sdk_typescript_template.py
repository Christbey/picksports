"""Patch only the pinned generator's required-nullable date serialization guards.

The OpenAPI contract, Date types and non-null conversion paths remain unchanged.
Run after `openapi-generator author template -g typescript-fetch` in build/sdk.
"""
import hashlib
import json
import os
import pathlib
import sys
import tempfile

UPSTREAM_SHA256 = "e194423998171cd1eaed32d16a817384075a8ace37ea677d7d34888e9135bdc5"
PATCHED_SHA256 = "66cddb51f5fc7e3750d3d60098a3d72fc2fa7ad0a4bcb17823bf6441dcf7472e"


def prepare(path):
    path = pathlib.Path(path)
    if path.name != "modelGeneric.mustache" or path.is_symlink():
        raise ValueError("Expected a generated regular model template")
    path.resolve().relative_to(pathlib.Path.cwd().resolve() / "build" / "sdk")
    original = path.read_bytes()
    if hashlib.sha256(original).hexdigest() != UPSTREAM_SHA256:
        raise ValueError("Pinned OpenAPI generator template changed; review required")
    value = original.decode("utf-8")
    guard = "{{#required}}{{#isNullable}}value['{{name}}'] === null ? null : {{/isNullable}}{{/required}}"
    for kind in ["isDateType", "isDateTimeType"]:
        anchor = "        {{#" + kind + "}}\n        '{{baseName}}': "
        if value.count(anchor) != 1:
            raise ValueError("Expected one date serialization branch")
        value = value.replace(anchor, anchor + guard)
    result = value.encode("utf-8")
    if hashlib.sha256(result).hexdigest() != PATCHED_SHA256:
        raise ValueError("Unexpected template patch delta")
    mode = path.stat().st_mode & 0o777
    with tempfile.NamedTemporaryFile(dir=path.parent, delete=False) as stream:
        temporary = pathlib.Path(stream.name)
        stream.write(result)
        stream.flush()
        os.fsync(stream.fileno())
    try:
        temporary.chmod(mode)
        os.replace(temporary, path)
    finally:
        if temporary.exists():
            temporary.unlink()
    return {"upstream_sha256": UPSTREAM_SHA256, "patched_sha256": PATCHED_SHA256, "serializer_lines_changed": 2}


if __name__ == "__main__":
    print(json.dumps(prepare(sys.argv[1]), sort_keys=True))
