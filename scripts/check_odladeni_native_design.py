#!/usr/bin/env python3
"""Static preflight only; never marks visual/editor/import reviews as passed."""
import argparse
import json
from collections import Counter
from pathlib import Path


def check_design(design):
    errors = []
    parts = design.get('parts', [])
    counts = Counter(p.get('type') for p in parts)
    for kind in ('header', 'footer'):
        if counts[kind] != 1:
            errors.append(f'Exactly one native {kind} is required; found {counts[kind]}.')
    if sum(p.get('role') == 'homepage' for p in parts) != 1:
        errors.append('Exactly one homepage role is required.')
    if design.get('styles'):
        errors.append('External/global styles are not portable; convert to registered native controls.')
    for part in parts:
        def walk(nodes):
            for node in nodes:
                widget = node.get('widgetType')
                if widget in ('html', 'shortcode', 'form', 'fluent-form-widget', 'eael-fluentform'):
                    errors.append(f"{part.get('key')}/{node.get('id')}: {widget} requires a verified adapter.")
                walk(node.get('elements', []))
        walk(part.get('template', {}).get('content', []))
        allowed = {'hide_title', 'background_background', 'background_color', 'background_color_b', 'background_gradient_type', 'background_gradient_angle', 'page_title_selector'}
        extra = set(part.get('template', {}).get('page_settings', {})) - allowed
        if extra:
            errors.append(f"{part.get('key')}: nonportable page settings: {', '.join(sorted(extra))}.")
    locations = {m.get('location') for m in design.get('menus', [])}
    if not {'header', 'footer'} <= locations:
        errors.append('Both header and footer WordPress menu mappings are required.')
    return {'static_pass': not errors, 'errors': errors, 'visual_verified': False,
            'editor_verified': False, 'import_verified': False,
            'next': 'Run the native staged importer and record actual evidence; static success is never a live deployment certificate.'}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('design_json', type=Path)
    args = parser.parse_args()
    result = check_design(json.loads(args.design_json.read_text(encoding='utf-8')))
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0 if result['static_pass'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
