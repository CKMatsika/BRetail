import { test, expect } from "@playwright/test";
import { build } from "esbuild";

// Exercise the real React editor without creating records in the live database.
let bundle: string;
let styles: string;
test.beforeAll(async () => {
    const result = await build({
        stdin: {
            contents: `import React from 'react';import {createRoot} from 'react-dom/client';import Trade from './resources/js/pages/Trade';createRoot(document.getElementById('root')).render(<Trade section={window.tradeSection} initial={window.tradeFixture} permissions={['*']}/>);`,
            resolveDir: process.cwd(),
            loader: "tsx",
        },
        bundle: true,
        write: false,
        outfile: "trade-fixture.js",
        format: "iife",
        define: { "process.env.NODE_ENV": '"test"' },
    });
    bundle = result.outputFiles.find((f) => f.path.endsWith(".js"))!.text;
    styles = result.outputFiles.find((f) => f.path.endsWith(".css"))!.text;
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
        company_logo_url:
            "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1kAAAAASUVORK5CYII=",
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
            body: `<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;padding:24px;font-family:Arial,sans-serif;color:#243c32;background:#f6f8f6}*{box-sizing:border-box}${styles}</style><div id="root"></div><script>window.tradeFixture=${JSON.stringify(data)}</script><script>${bundle.replaceAll("</script", "<\\/script")}</script>`,
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
            number: original.number,
            has_history: true,
            version: 2,
            net: "100.00",
            tax: "15.00",
            total: "115.00",
            items: [row],
        };
        data.documents = [revision];
        return route.fulfill({ json: { data: revision } });
    });
    const errors: string[] = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto("/editor-test");
    await page.getByRole("button", { name: "Quotations", exact: true }).click();
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
        .getByRole("button", { name: "Save quotation changes", exact: true })
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
        name: "Invoice preview | QUOTE-000001",
        exact: true,
    });
    await expect(invoice).toContainText("Total: USD 115.00");
    await expect(
        invoice.getByRole("img", { name: "Company logo" }),
    ).toBeVisible();
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

const workspaceFixture = () => ({
    book: "training",
    today: "2026-11-04",
    currency: "USD",
    purchasing: true,
    sales: true,
    warehouses: [{ id: 1, name: "Main", branch_id: 1 }],
    customers: [
        { id: 1, name: "Acacia Stores" },
        { id: 2, name: "Beacon Foods" },
    ],
    suppliers: [
        {
            id: 1,
            code: "SUP-01",
            name: "Central Supply",
            terms_days: 30,
            active: true,
        },
        {
            id: 2,
            code: "SUP-02",
            name: "Delta Wholesale",
            terms_days: 14,
            active: true,
        },
    ],
    orders: [] as any[],
    documents: [] as any[],
    variants: [{ id: 1, sku: "ITEM", name: "Standard", selling_price: "10" }],
    cash_accounts: [{ id: 1, code: "1000", name: "Bank" }],
    report: { aging: [], controls: [] },
});
const workspaceLine = {
    variant_id: 1,
    sku: "ITEM",
    description: "Standard",
    quantity: "2",
    unit_cost: "5",
    unit_price: "10",
    discount_percent: "0",
    total: "20",
};

test("customer workspace keeps documents together and receives payment against the correct invoice", async ({
    page,
}, info) => {
    const data = workspaceFixture();
    data.documents = [
        {
            id: 101,
            kind: "quote",
            customer_id: 1,
            number: "QT-101",
            party_name: "Acacia Stores",
            date: data.today,
            due_on: "2026-11-30",
            total: "20",
            items: [workspaceLine],
        },
        {
            id: 102,
            kind: "quote",
            customer_id: 2,
            number: "QT-102",
            party_name: "Beacon Foods",
            date: data.today,
            total: "20",
            items: [workspaceLine],
        },
    ];
    const errors: string[] = [];
    page.on("pageerror", (e) => errors.push(e.message));
    const posted: string[] = [];
    await page.route("**/workspace-test", (route) =>
        route.fulfill({
            contentType: "text/html",
            body: `<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;padding:20px;font-family:Arial;color:#243c32;background:#f6f8f6}*{box-sizing:border-box}${styles}</style><div id="root"></div><script>window.tradeSection='sales';window.tradeFixture=${JSON.stringify(data)}</script><script>${bundle.replaceAll("</script", "<\\/script")}</script>`,
        }),
    );
    await page.route("**/trade/**", async (route) => {
        const path = new URL(route.request().url()).pathname;
        if (route.request().method() === "GET")
            return route.fulfill({ json: { data } });
        posted.push(path);
        if (path === "/trade/invoices/101") {
            expect(route.request().postDataJSON().accepted).toBe(true);
            data.documents[0].quote_closed = true;
            data.documents.unshift({
                ...data.documents[0],
                id: 103,
                kind: "invoice",
                number: "INV-103",
                parent_id: 101,
                outstanding: "20",
                warehouse_id: 1,
            });
        } else if (path === "/trade/settlements/103") {
            expect(route.request().postDataJSON().amount).toBe("20");
            const invoice = data.documents.find((d) => d.id === 103)!;
            invoice.outstanding = "0";
            data.documents.unshift({
                ...invoice,
                id: 104,
                kind: "customer_receipt",
                number: "RCPT-104",
                parent_id: 103,
                outstanding: null,
            });
        } else throw new Error(`Unexpected action ${path}`);
        return route.fulfill({ json: { data: {} } });
    });
    await page.goto("/workspace-test");
    await expect(
        page.getByRole("heading", { name: "Customers & sales", exact: true }),
    ).toBeVisible();
    const tabs = page.getByRole("navigation", {
        name: "Customers & sales documents",
    });
    await expect(
        tabs.getByRole("button", { name: "Purchase orders", exact: true }),
    ).toHaveCount(0);
    await page
        .getByRole("row")
        .filter({ hasText: "Acacia Stores" })
        .getByRole("button", { name: "View customer documents" })
        .click();
    await expect(page.getByText("QT-101", { exact: true })).toBeVisible();
    await expect(page.getByText("QT-102", { exact: true })).toHaveCount(0);
    await expect(page.getByLabel("Customer", { exact: true })).toHaveValue("1");
    await page.getByLabel("Customer workspace").selectOption("2");
    await expect(page.getByLabel("Customer", { exact: true })).toHaveValue("2");
    await expect(page.getByText("QT-101", { exact: true })).toHaveCount(0);
    await page.getByLabel("Customer workspace").selectOption("1");
    await expect(page.getByLabel("Customer", { exact: true })).toHaveValue("1");
    await page
        .getByRole("button", { name: "Accept and invoice", exact: true })
        .click();
    await page
        .getByRole("button", {
            name: "Accept quote and post credit invoice",
            exact: true,
        })
        .click();
    await expect(page.getByText("INV-103", { exact: true })).toBeVisible();
    await page
        .getByRole("button", { name: "Receive payment", exact: true })
        .click();
    await page.getByLabel("Reference", { exact: true }).fill("BANK-104");
    await page
        .getByRole("button", { name: "Post payment", exact: true })
        .click();
    await expect(
        page.getByRole("button", { name: "Receive payment", exact: true }),
    ).toHaveCount(0);
    await tabs
        .getByRole("button", { name: "Customer receipts", exact: true })
        .click();
    await expect(page.getByText("RCPT-104", { exact: true })).toBeVisible();
    await page
        .getByRole("button", { name: "From INV-103", exact: true })
        .click();
    await expect(page.getByText("INV-103", { exact: true })).toBeVisible();
    await expect(page.getByLabel("Search documents")).toHaveValue("INV-103");
    expect(posted).toEqual(["/trade/invoices/101", "/trade/settlements/103"]);
    await page.screenshot({
        path: `test-results/customer-workspace-${info.project.name}.png`,
        fullPage: true,
    });
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    expect(errors).toEqual([]);
});

test("procurement follows approval, receipt, supplier invoice and payment within a supplier workspace", async ({
    page,
}, info) => {
    const data = workspaceFixture();
    data.orders = [
        {
            id: 201,
            supplier_id: 1,
            supplier_name: "Central Supply",
            number: "PO-201",
            date: data.today,
            status: "draft",
            items: [workspaceLine],
        },
        {
            id: 205,
            supplier_id: 2,
            supplier_name: "Delta Wholesale",
            number: "PO-205",
            date: data.today,
            status: "draft",
            items: [workspaceLine],
        },
    ];
    const posted: string[] = [];
    await page.route("**/workspace-test", (route) =>
        route.fulfill({
            contentType: "text/html",
            body: `<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;padding:20px;font-family:Arial;color:#243c32;background:#f6f8f6}*{box-sizing:border-box}${styles}</style><div id="root"></div><script>window.tradeSection='purchasing';window.tradeFixture=${JSON.stringify(data)}</script><script>${bundle.replaceAll("</script", "<\\/script")}</script>`,
        }),
    );
    await page.route("**/trade/**", async (route) => {
        const path = new URL(route.request().url()).pathname;
        if (route.request().method() === "GET")
            return route.fulfill({ json: { data } });
        posted.push(path);
        const base = {
            supplier_id: 1,
            party_name: "Central Supply",
            warehouse_id: 1,
            date: data.today,
            total: "10",
            items: [workspaceLine],
            order_id: 201,
        };
        if (path === "/trade/approve/201") data.orders[0].status = "approved";
        else if (path === "/trade/receipts/201") {
            data.orders[0].status = "completed";
            data.documents.unshift({
                ...base,
                id: 202,
                kind: "receipt",
                number: "GRN-202",
            });
        } else if (path === "/trade/bills/202")
            data.documents.unshift({
                ...base,
                id: 203,
                kind: "bill",
                number: "BILL-203",
                parent_id: 202,
                outstanding: "10",
            });
        else if (path === "/trade/settlements/203") {
            data.documents.find((d) => d.id === 203).outstanding = "0";
            data.documents.unshift({
                ...base,
                id: 204,
                kind: "supplier_payment",
                number: "PAY-204",
                parent_id: 203,
            });
        } else throw new Error(`Unexpected action ${path}`);
        return route.fulfill({ json: { data: {} } });
    });
    await page.goto("/workspace-test");
    await expect(
        page.getByRole("heading", { name: "Procurement", exact: true }),
    ).toBeVisible();
    await page
        .getByRole("row")
        .filter({ hasText: "Central Supply" })
        .getByRole("button", { name: "View supplier documents" })
        .click();
    await expect(page.getByText("PO-205", { exact: true })).toHaveCount(0);
    await expect(
        page.getByRole("button", { name: "Receive goods", exact: true }),
    ).toHaveCount(0);
    await page.getByRole("button", { name: "Approve", exact: true }).click();
    await page
        .getByRole("button", { name: "Receive goods", exact: true })
        .click();
    await page.getByLabel("Reference", { exact: true }).fill("DELIVERY-202");
    await page.getByLabel("Quantity 1", { exact: true }).fill("2");
    await page
        .getByRole("button", { name: "Post goods receipt", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Match supplier invoice", exact: true })
        .click();
    await page.getByLabel("Reference", { exact: true }).fill("SUP-INV-203");
    await page.getByLabel("Quantity 1", { exact: true }).fill("2");
    await page
        .getByRole("button", { name: "Post supplier invoice", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Pay supplier invoice", exact: true })
        .click();
    await page.getByLabel("Reference", { exact: true }).fill("BANK-204");
    await page
        .getByRole("button", { name: "Post payment", exact: true })
        .click();
    await page
        .getByRole("navigation", { name: "Procurement documents" })
        .getByRole("button", { name: "Supplier payments", exact: true })
        .click();
    await expect(page.getByText("PAY-204", { exact: true })).toBeVisible();
    await expect(
        page.getByRole("button", { name: "From BILL-203", exact: true }),
    ).toBeVisible();
    expect(posted).toEqual([
        "/trade/approve/201",
        "/trade/receipts/201",
        "/trade/bills/202",
        "/trade/settlements/203",
    ]);
    await page.screenshot({
        path: `test-results/procurement-workspace-${info.project.name}.png`,
        fullPage: true,
    });
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    await page
        .getByRole("navigation", { name: "Commercial areas" })
        .getByRole("button", { name: "Back office", exact: true })
        .click();
    await expect(
        page.getByRole("heading", {
            name: "Outstanding balances and aging",
            exact: true,
        }),
    ).toBeVisible();
    await expect(page.getByLabel("Supplier workspace")).toHaveCount(0);
});

test("invoice editing saves under the same number without creating a quotation, with history and protected paid details", async ({
    page,
}, info) => {
    const data = workspaceFixture();
    const original = {
        id: 301,
        kind: "invoice",
        number: "INV-301",
        party_name: "Acacia Stores",
        customer_id: 1,
        warehouse_id: 1,
        date: data.today,
        due_on: "2026-12-04",
        reference: "PO-SCHOOL",
        notes: "Original",
        net: "20",
        tax: "0",
        total: "20",
        outstanding: "20",
        invoice_editable: true,
        items: [workspaceLine],
    };
    data.documents = [original];
    const posted: string[] = [];
    await page.route("**/workspace-test", (route) =>
        route.fulfill({
            contentType: "text/html",
            body: `<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{padding:20px;font-family:Arial;color:#243c32;background:#f6f8f6}*{box-sizing:border-box}${styles}</style><div id="root"></div><script>window.tradeSection='sales';window.tradeFixture=${JSON.stringify(data)}</script><script>${bundle.replaceAll("</script", "<\\/script")}</script>`,
        }),
    );
    await page.route("**/trade/**", async (route) => {
        const path = new URL(route.request().url()).pathname;
        if (path === "/trade/history/302")
            return route.fulfill({
                json: {
                    data: [
                        data.documents[0],
                        { ...original, version: 1, superseded: true },
                    ],
                    details: [],
                },
            });
        if (route.request().method() === "GET")
            return route.fulfill({ json: { data } });
        posted.push(path);
        if (path === "/trade/invoice-edits/301") {
            const body = route.request().postDataJSON();
            expect(body.customer_id).toBe("1");
            expect(body.items[0].unit_price).toBe("12");
            data.documents = [
                {
                    ...original,
                    id: 302,
                    parent_id: 301,
                    number: original.number,
                    notes: "Amended",
                    total: "24",
                    outstanding: "24",
                    version: 2,
                    has_history: true,
                    items: [{ ...workspaceLine, unit_price: "12" }],
                },
            ];
        } else if (path === "/trade/invoice-details/302") {
            data.documents[0] = {
                ...data.documents[0],
                reference: route.request().postDataJSON().reference,
            };
        } else if (path === "/trade/settlements/302") {
            data.documents[0] = {
                ...data.documents[0],
                outstanding: "19",
                invoice_editable: false,
            };
        } else throw new Error(`Unexpected action ${path}`);
        return route.fulfill({ json: { data: {} } });
    });
    await page.goto("/workspace-test");
    const tabs = page.getByRole("navigation", {
        name: "Customers & sales documents",
    });
    await tabs.getByRole("button", { name: "Invoices", exact: true }).click();
    await page
        .getByRole("button", { name: "Edit invoice", exact: true })
        .click();
    await expect(
        page.getByRole("heading", {
            name: "Edit invoice INV-301",
            exact: true,
        }),
    ).toBeVisible();
    await expect(page.getByLabel("Customer", { exact: true })).toBeDisabled();
    await page.getByLabel("Unit price 1", { exact: true }).fill("12");
    await page
        .getByRole("button", { name: "Save invoice changes", exact: true })
        .click();
    await expect(
        page.getByText("Invoice updated successfully.", { exact: true }),
    ).toBeVisible();
    await expect(page.getByText("INV-301", { exact: true })).toHaveCount(1);
    await page.getByRole("button", { name: "History", exact: true }).click();
    const history = page.getByRole("region", { name: "Document history" });
    await expect(history).toContainText("History · INV-301");
    await expect(history).toContainText("Previous version");
    await page.screenshot({
        path: `test-results/invoice-history-${info.project.name}.png`,
        fullPage: true,
    });
    await page
        .getByRole("button", { name: "Close history", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Receive payment", exact: true })
        .click();
    await page.getByLabel("Reference", { exact: true }).fill("BANK-PAY");
    await page.getByLabel("Payment amount", { exact: true }).fill("5");
    await page
        .getByRole("button", { name: "Post payment", exact: true })
        .click();
    await expect(
        page.getByRole("button", { name: "Edit invoice", exact: true }),
    ).toHaveCount(0);
    await page
        .getByRole("button", { name: "Edit reference / notes", exact: true })
        .click();
    await page
        .getByLabel("Reference", { exact: true })
        .fill("PO-SCHOOL-UPDATED");
    await page
        .getByRole("button", { name: "Save invoice details", exact: true })
        .click();
    await expect(
        page.getByText("PO-SCHOOL-UPDATED", { exact: true }),
    ).toBeVisible();
    expect(posted).toEqual([
        "/trade/invoice-edits/301",
        "/trade/settlements/302",
        "/trade/invoice-details/302",
    ]);
    await tabs.getByRole("button", { name: "Quotations", exact: true }).click();
    await expect(
        page.getByText("No quotations found", { exact: true }),
    ).toBeVisible();
});
