import { test, expect } from "@playwright/test";
import { build } from "esbuild";

// Exercise the real React editor without creating records in the live database.
let bundle: string;
test.beforeAll(async () => {
    const result = await build({
        stdin: {
            contents: `import React from 'react';import {createRoot} from 'react-dom/client';import Trade from './resources/js/pages/Trade';createRoot(document.getElementById('root')).render(<Trade initial={window.tradeFixture} permissions={['*']}/>);`,
            resolveDir: process.cwd(),
            loader: "tsx",
        },
        bundle: true,
        write: false,
        format: "iife",
        define: { "process.env.NODE_ENV": '"test"' },
    });
    bundle = result.outputFiles[0].text;
});

test("quote editor previews inclusive VAT, saves a revision and previews the invoice", async ({
    page,
}, info) => {
    const line = {
        variant_id: 1,
        sku: "ITEM",
        description: "Test item",
        quantity: "1",
        unit_price: "115",
        discount_percent: "0",
        tax_rate: "15",
        net: "115.00",
        tax: "17.25",
        total: "132.25",
        prices_include_vat: false,
    };
    const original = {
        id: 1,
        kind: "quote",
        number: "QUOTE-000001",
        party_name: "Customer",
        customer_id: 1,
        warehouse_id: 1,
        date: "2026-11-03",
        due_on: "2026-11-30",
        reference: "CUSTOMER-REF",
        notes: "Keep this note",
        quote_closed: false,
        net: "115.00",
        tax: "17.25",
        total: "132.25",
        items: [line],
    };
    const data = {
        company_logo_url: "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1kAAAAASUVORK5CYII=",
        book: "training",
        today: "2026-11-03",
        currency: "USD",
        purchasing: false,
        sales: true,
        warehouses: [{ id: 1, name: "Main" }],
        customers: [{ id: 1, name: "Customer" }],
        suppliers: [],
        orders: [],
        documents: [original],
        variants: [
            { id: 1, sku: "ITEM", name: "Standard", selling_price: "115" },
        ],
        cash_accounts: [],
        report: { aging: [], controls: [] },
    };
    let previewCalls = 0;
    await page.route("**/editor-test", (route) =>
        route.fulfill({
            contentType: "text/html",
            body: `<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><div id="root"></div><script>window.tradeFixture=${JSON.stringify(data)}</script><script>${bundle.replaceAll("</script", "<\\/script")}</script>`,
        }),
    );
    await page.route("**/trade/**", async (route) => {
        if (route.request().method() === "GET")
            return route.fulfill({ json: { data } });
        const payload = route.request().postDataJSON();
        expect(payload.prices_include_vat).toBe(true);
        expect(payload.reference).toBe("CUSTOMER-REF");
        expect(payload.notes).toBe("Keep this note");
        const row = {
            ...line,
            ...payload.items[0],
            prices_include_vat: true,
            net: "100.00",
            tax: "15.00",
            total: "115.00",
        };
        if (route.request().url().includes("/quote-preview/")) {
            previewCalls++;
            return route.fulfill({
                json: {
                    data: {
                        items: [row],
                        net: "100.00",
                        tax: "15.00",
                        total: "115.00",
                        currency_snapshot: null,
                    },
                },
            });
        }
        expect(route.request().url()).toContain("/quotes/1");
        const revision = {
            ...original,
            id: 2,
            parent_id: 1,
            number: "QUOTE-000002",
            net: "100.00",
            tax: "15.00",
            total: "115.00",
            items: [row],
        };
        data.documents = [revision, { ...original, quote_closed: true }];
        return route.fulfill({ json: { data: revision } });
    });
    const errors: string[] = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto("/editor-test");
    await page.getByRole("button", { name: "Quotes", exact: true }).click();
    await page
        .getByRole("button", { name: "Edit quotation", exact: true })
        .click();
    await expect(page.getByLabel("Reference", { exact: true })).toHaveValue(
        "CUSTOMER-REF",
    );
    await page.getByLabel("Unit prices", { exact: true }).selectOption("true");
    await page
        .getByRole("button", { name: "Preview totals", exact: true })
        .click();
    const preview = page.getByRole("region", {
        name: "Quotation / invoice totals preview",
        exact: true,
    });
    await expect(preview).toContainText("Total: USD 115.00");
    await expect(preview).toContainText("VAT included");
    await page.getByLabel("Unit price 1", { exact: true }).fill("116");
    await expect(preview).toHaveCount(0);
    await page.getByLabel("Unit price 1", { exact: true }).fill("115");
    await page
        .getByRole("button", { name: "Preview totals", exact: true })
        .click();
    await expect(preview).toBeVisible();
    await page
        .getByRole("button", { name: "Save revised quotation", exact: true })
        .click();
    await expect(
        page.getByText("Quotation saved successfully.", { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole("button", { name: "Edit quotation", exact: true }),
    ).toHaveCount(1);
    await page
        .getByRole("button", { name: "Accept and invoice", exact: true })
        .click();
    const invoice = page.getByRole("region", {
        name: "Invoice preview | QUOTE-000002",
        exact: true,
    });
    await expect(invoice).toContainText("Total: USD 115.00");
    await expect(invoice.getByRole("img", {name:"Company logo"})).toBeVisible();
    await page.screenshot({
        path: `test-results/quote-preview-${info.project.name}.png`,
        fullPage: true,
    });
    await page
        .getByRole("button", {
            name: "Edit quotation before invoicing",
            exact: true,
        })
        .click();
    await expect(page.getByLabel("Unit prices", { exact: true })).toHaveValue(
        "true",
    );
    expect(previewCalls).toBe(2);
    expect(errors).toEqual([]);
});
