import copy
import json
import pathlib
import unittest
from prepare_sdk_spec import convert, prepare


class SdkSpecificationTest(unittest.TestCase):
    def test_preserves_published_contract_and_authorization_alternatives(self):
        source = json.loads((pathlib.Path(__file__).resolve().parents[1] / "docs/openapi-v2.json").read_text())
        before = copy.deepcopy(source)
        normalized = prepare(source)
        self.assertEqual(source, before)
        self.assertEqual(normalized["openapi"], "3.0.3")
        self.assertIn("nativeOAuth", normalized["components"]["securitySchemes"])
        self.assertNotIn("developerApiCredential", normalized["components"]["securitySchemes"])
        for path, operations in source["paths"].items():
            for method, operation in operations.items():
                if not isinstance(operation, dict) or "security" not in operation:
                    continue
                expected = [{("sanctumBearer" if key == "developerApiCredential" else key): value for key, value in item.items()} for item in operation["security"]]
                self.assertEqual(normalized["paths"][path][method]["security"], expected)

    def test_preserves_nullable_references_and_primitive_unions(self):
        self.assertEqual(convert({"oneOf": [{"$ref": "#/components/schemas/Forecast"}, {"type": "null"}]}), {"allOf": [{"$ref": "#/components/schemas/Forecast"}], "nullable": True})
        self.assertEqual(convert({"type": ["integer", "string", "null"]}), {"nullable": True, "oneOf": [{"type": "integer"}, {"type": "string"}]})

    def test_preserves_prohibited_fields_and_constant_values(self):
        self.assertEqual(convert({"properties": {"forbidden": False}, "additionalProperties": False}), {"properties": {"forbidden": {"not": {}}}, "additionalProperties": False})
        self.assertEqual(convert({"const": True}), {"type": "boolean", "x-json-schema-const": True})

    def test_transports_opaque_json_union_without_losing_wire_values(self):
        self.assertEqual(convert({"oneOf": [{"type": "object", "additionalProperties": True}, {"type": "array", "maxItems": 0}]}), {"description": "Opaque JSON object or array; transported without conversion."})

    def test_rejects_different_authentication_transports(self):
        source = json.loads((pathlib.Path(__file__).resolve().parents[1] / "docs/openapi-v2.json").read_text())
        source["components"]["securitySchemes"]["developerApiCredential"]["scheme"] = "basic"
        with self.assertRaises(ValueError):
            prepare(source)

    def test_preserves_standalone_null_only_schema_and_annotations(self):
        schema = {
            "type": "null",
            "description": "No qualified observation instant is available.",
            "readOnly": True,
        }
        before = copy.deepcopy(schema)
        self.assertEqual(convert(schema), {
            "type": "object",
            "nullable": True,
            "not": {"type": "object"},
            "description": schema["description"],
            "readOnly": True,
        })
        self.assertEqual(schema, before)

    def test_preserves_existing_negative_constraint_on_null_only_schema(self):
        self.assertEqual(convert({"type": "null", "not": {"enum": [None]}}), {
            "type": "object",
            "nullable": True,
            "not": {"anyOf": [{"enum": [None]}, {"type": "object"}]},
        })

    def test_real_observation_fields_remain_null_only_without_null_model_types(self):
        source = json.loads((pathlib.Path(__file__).resolve().parents[1] / "docs/openapi-v2.json").read_text())
        before = copy.deepcopy(source)
        normalized = prepare(source)
        original_schemas = source["components"]["schemas"]
        schemas = normalized["components"]["schemas"]
        pairs = [
            (original_schemas["NflLiveSnapshotData"]["properties"], schemas["NflLiveSnapshotData"]["properties"], "source_updated_at"),
            (original_schemas["NflLiveSnapshotData"]["properties"], schemas["NflLiveSnapshotData"]["properties"], "record_updated_at"),
            (original_schemas["SportPlayer"]["properties"]["active_injuries"]["items"]["properties"], schemas["SportPlayer"]["properties"]["active_injuries"]["items"]["properties"], "source_updated_at"),
        ]
        for original, adapted, field in pairs:
            with self.subTest(field=field, description=original[field]["description"]):
                self.assertEqual(original[field]["type"], "null")
                self.assertEqual(adapted[field], {
                    **original[field],
                    "type": "object",
                    "nullable": True,
                    "not": {"type": "object"},
                })
        self.assertEqual(source, before)
        self.assertEqual(schemas["NflLiveSnapshotData"].get("required"), original_schemas["NflLiveSnapshotData"].get("required"))

if __name__ == "__main__":
    unittest.main()
