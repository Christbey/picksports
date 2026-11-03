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
        self.assertEqual(convert({"const": True}), {"enum": [True]})

    def test_rejects_different_authentication_transports(self):
        source = json.loads((pathlib.Path(__file__).resolve().parents[1] / "docs/openapi-v2.json").read_text())
        source["components"]["securitySchemes"]["developerApiCredential"]["scheme"] = "basic"
        with self.assertRaises(ValueError):
            prepare(source)


if __name__ == "__main__":
    unittest.main()
