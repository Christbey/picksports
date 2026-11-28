import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import ts from 'typescript';

const root = fileURLToPath(new URL('../../', import.meta.url));
const globals = path.join(root, 'resources/js/types/globals.d.ts');
const fixture = path.join(root, 'resources/js/__global_type_contract__.ts');
const source = `
import type { ComponentCustomProperties } from 'vue';
declare const component: ComponentCustomProperties;
component.$inertia.visit('/contract');
component.$headManager.createProvider();
const user = component.$page.props.auth.user;
// @ts-expect-error A public page may have no authenticated user.
const uncheckedName: string = user.name;
if (user) {
    const name: string = user.name;
    // @ts-expect-error Shared account names are strings, never arbitrary values.
    const invalidName: number = user.name;
    void name;
    void invalidName;
}
void uncheckedName;
// @ts-expect-error An unknown router method must remain a type error.
component.$inertia.notAnInertiaMethod();
// @ts-expect-error The head manager must keep its official API.
component.$headManager.notAHeadMethod();
`;
const configuration = ts.readConfigFile(
    path.join(root, 'tsconfig.json'),
    ts.sys.readFile,
);
assert.equal(configuration.error, undefined);
const parsed = ts.parseJsonConfigFileContent(
    configuration.config,
    ts.sys,
    root,
);
const options = { ...parsed.options, skipLibCheck: false, noEmit: true };
const host = ts.createCompilerHost(options);
const readFile = host.readFile.bind(host);
const fileExists = host.fileExists.bind(host);
host.readFile = (file) => (file === fixture ? source : readFile(file));
host.fileExists = (file) => file === fixture || fileExists(file);
const program = ts.createProgram([globals, fixture], options, host);
const format = (diagnostics) =>
    ts.formatDiagnosticsWithColorAndContext(diagnostics, {
        getCanonicalFileName: (file) => file,
        getCurrentDirectory: () => root,
        getNewLine: () => '\n',
    });

test('repository global declarations resolve without relying on skipLibCheck', () => {
    const diagnostics = program.getSemanticDiagnostics(
        program.getSourceFile(globals),
    );
    assert.equal(diagnostics.length, 0, format(diagnostics));
});

test('Vue globals preserve official router, head manager and shared-page types', () => {
    const diagnostics = program.getSemanticDiagnostics(
        program.getSourceFile(fixture),
    );
    assert.equal(diagnostics.length, 0, format(diagnostics));
});
