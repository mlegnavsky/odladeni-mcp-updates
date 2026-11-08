import copy
import unittest
from check_odladeni_native_design import check_design


def fixture():
    def part(key, kind, role='other'):
        return {'key': key, 'type': kind, 'role': role, 'template': {
            'content': [{'id': key, 'elType': 'section', 'settings': {}, 'elements': []}],
            'page_settings': {'hide_title': 'yes'}}}
    return {'parts': [part('header', 'header'), part('footer', 'footer'), part('home', 'page', 'homepage')],
            'menus': [{'location': 'header'}, {'location': 'footer'}], 'styles': ''}


class PreflightTests(unittest.TestCase):
    def test_static_success_never_claims_live_or_visual_validation(self):
        result = check_design(fixture())
        self.assertTrue(result['static_pass'])
        for key in ('visual_verified', 'editor_verified', 'import_verified'):
            self.assertFalse(result[key])

    def test_missing_shared_parts_and_external_styles_fail(self):
        design = fixture()
        design['parts'] = design['parts'][2:]
        design['styles'] = 'body { color: red; }'
        result = check_design(design)
        self.assertFalse(result['static_pass'])
        self.assertEqual(len(result['errors']), 3)

    def test_nested_html_and_theme_page_template_are_rejected(self):
        design = copy.deepcopy(fixture())
        design['parts'][2]['template']['content'][0]['elements'] = [
            {'id': 'raw', 'elType': 'widget', 'widgetType': 'html', 'elements': []}]
        design['parts'][2]['template']['page_settings']['template'] = 'elementor_header_footer'
        result = check_design(design)
        self.assertFalse(result['static_pass'])
        self.assertTrue(any('html requires' in e for e in result['errors']))
        self.assertTrue(any('nonportable page settings' in e for e in result['errors']))


if __name__ == '__main__':
    unittest.main()
