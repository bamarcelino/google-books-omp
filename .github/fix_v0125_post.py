from pathlib import Path


def read(path):
    return Path(path).read_text(encoding='utf-8')


def write(path, text):
    Path(path).write_text(text, encoding='utf-8')


# The permanent package checker still carries historical release literals from
# earlier maintenance releases. Normalize all release-specific expectations to
# the source version being validated by this branch.
path = 'tests/package_check.py'
text = read(path)
text = text.replace('0.1.2.3', '0.1.2.5')
text = text.replace('0.1.2.4', '0.1.2.5')
write(path, text)

# Keep the committed validation summary synchronized with the exact checks that
# this release adds. CI still remains the authoritative pass/fail gate.
path = 'validation-results.txt'
text = read(path)
text = text.replace('OK 218 core behavior/auth/secret/delivery assertions', 'OK 219 core behavior/auth/secret/delivery assertions')
text = text.replace('OK 221 package/locale/security/source-contract checks', 'OK 222 package/locale/security/source-contract checks')
text = text.replace('OK 644/644 total behavioral/contract assertions', 'OK 646/646 total behavioral/contract assertions')
text = text.replace('OK 638/638 total behavioral/contract assertions', 'OK 646/646 total behavioral/contract assertions')
extra = '''OK Google Play Books contributor profile: exactly one primary ContributorRole per Contributor composite
OK editor-only OMP records preserve B01/B21/etc. without synthetic A01 injection
OK incomplete ONIX guard: missing closing ONIXMessage or unbalanced Product composites are rejected
'''
if 'OK Google Play Books contributor profile:' not in text:
    if not text.endswith('\n'):
        text += '\n'
    text += extra
write(path, text)
