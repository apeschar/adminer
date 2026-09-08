import {expect, request, test} from '@playwright/test';
import {button, expectNoErrors, goto, newPage, setValue} from './adminer.js';

test.describe.configure({mode: 'serial'});

const server = process.env.CLICKHOUSE_URL || 'http://localhost:8123';
const database = 'adminer_binary_test';
const params = new URLSearchParams({clickhouse: server, username: 'ODBC', db: database});
let page;
let api;

async function query(sql) {
	const response = await api.post(server, {
		headers: {Authorization: 'Basic ' + Buffer.from('ODBC:ODBC').toString('base64')},
		data: sql,
	});
	expect(response.ok(), await response.text()).toBeTruthy();
	return response.text();
}

test.beforeAll(async ({browser}) => {
	test.skip(test.info().project.name !== 'native', 'ClickHouse uses HTTP, not PDO.');
	api = await request.newContext();
	await query('DROP DATABASE IF EXISTS ' + database);
	await query('CREATE DATABASE ' + database);
	await query('CREATE TABLE ' + database + '.binary_values (id FixedString(4), value String, optional Nullable(FixedString(4)), note String) ENGINE=MergeTree ORDER BY id');
	await query("INSERT INTO " + database + ".binary_values VALUES (unhex('FF004100'),unhex('00FF1B07'),NULL,'first'),(unhex('FF004101'),'x',unhex('FF000000'),'second'),(unhex('61626300'),'café',NULL,'padding'),('0123','0x1234',NULL,'printable')");
	page = await newPage(browser);
	await goto(page, '/tests/clickhouse.php?' + params);
	await page.locator('[name="lang"]').selectOption({label: 'English'});
	await page.locator('#username').fill('ODBC');
	await page.locator('[name="auth[password]"]').fill('ODBC');
	await button(page, 'Login').click();
});

test.afterEach(async () => {
	await expectNoErrors();
});

test.afterAll(async () => {
	if (page) {
		await page.close();
	}
	if (api) {
		await query('DROP DATABASE IF EXISTS ' + database);
		await api.dispose();
	}
});

test('Binary cells display as hex without changing printable text', async () => {
	await goto(page, '/tests/clickhouse.php?' + params + '&select=binary_values');
	for (const text of ['0xff004100', '0x00ff1b07', '0xff004101', '0xff000000', '0x61626300', 'café', '0x1234']) {
		await expect(page.locator('#content')).toContainText(text);
	}
	await expect(page.locator('#content .edit')).toHaveCount(4);
});

test('Edit link selects the exact row and unchanged hex input preserves bytes', async () => {
	const row = page.locator('tr').filter({hasText: 'first'}).filter({has: page.locator('a.edit')});
	await row.locator('a.edit').click();
	await expect(page.locator('[name="fields[note]"]')).toHaveValue('first');
	await expect(page.locator('[name="fields[value]"]')).toHaveValue('00ff1b07');
	await expect(page.locator('[name="function[value]"]')).toHaveValue('unhex');
	await page.locator('[name="fields[note]"]').fill('edited');
	await button(page, 'Save').click();
	await expect.poll(() => query('SELECT hex(id),hex(value),note FROM ' + database + '.binary_values ORDER BY id FORMAT TSV')).toContain('FF004100\t00FF1B07\tedited');
	expect(await query('SELECT hex(id),note FROM ' + database + ".binary_values WHERE note='second' FORMAT TSV")).toContain('FF004101\tsecond');
});

test('SQL results also display arbitrary bytes as hex', async () => {
	await goto(page, '/tests/clickhouse.php?' + params + '&sql=');
	await setValue(page, 'query', "SELECT unhex('FF004100') AS binary, 'café' AS text");
	await button(page, 'Execute').click();
	await expect(page.locator('#content')).toContainText('0xff004100');
	await expect(page.locator('#content')).toContainText('café');
});
