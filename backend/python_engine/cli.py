import sys
import os
import argparse
import json
import re

# Add engine directory to path
sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from registry import get_driver

# Kode keluar khusus agar PHP bisa membedakan "engine gagal" dari
# "method memang tidak didukung" dan melanjutkan ke driver PHP native.
EXIT_UNSUPPORTED = 3

def camel_to_snake(name: str) -> str:
    s1 = re.sub('(.)([A-Z][a-z]+)', r'\1_\2', name)
    return re.sub('([a-z0-9])([A-Z])', r'\1_\2', s1).lower()

def main():
    parser = argparse.ArgumentParser(description="SmartOLT Python CLI Fallback Runner")
    parser.add_argument("--olt-data", required=True, help="JSON string of OLT details")
    parser.add_argument("--method", required=True, help="PHP driver method name")
    parser.add_argument("--args", default="[]", help="JSON string of method arguments")
    
    args = parser.parse_args()

    try:
        olt = json.loads(args.olt_data)
        method_args = json.loads(args.args)
    except Exception as e:
        print(json.dumps({
            "success": False,
            "message": f"CLI Error: Invalid JSON input format. Details: {str(e)}"
        }))
        sys.exit(1)

    olt_type = olt.get('type', '')
    driver = get_driver(olt)
    if not driver:
        print(json.dumps({
            "success": False,
            "message": f"CLI Error: Driver for OLT type '{olt_type}' not found."
        }))
        sys.exit(1)

    py_method_name = camel_to_snake(args.method)
    if py_method_name.startswith('_') or not hasattr(driver, py_method_name):
        # JANGAN cetak JSON ber-key 'success' di sini: PHP akan menganggapnya
        # jawaban sah dan berhenti, sehingga driver PHP native tak pernah dicoba.
        print(f"UNSUPPORTED: method '{py_method_name}' tidak ada pada driver.", file=sys.stderr)
        sys.exit(EXIT_UNSUPPORTED)

    method = getattr(driver, py_method_name)

    try:
        result = method(olt, *method_args)
        print(json.dumps(result))
    except Exception as e:
        print(json.dumps({
            "success": False,
            "message": f"CLI Execution Error: {str(e)}"
        }))
        sys.exit(1)

if __name__ == "__main__":
    main()
