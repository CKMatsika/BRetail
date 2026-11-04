import { test, expect } from "@playwright/test";
import { build } from "esbuild";
import { readFileSync } from "node:fs";
let bundle: string, styles: string;
const zero = {
    current: "0",
    "1–30": "0",
    "31–60": "0",
    "61–90": "0",
    "90+": "0",
    total: "0",
};
const totals = { ...zero, current: "8460", "31–60": "1200", total: "9660" };
const financial = {
    trial_balance: [
        {
            id: 1,
            code: "1100",
            name: "Trade receivables",
            type: "asset",
            opening: "0",
            debit: "9660",
            credit: "0",
            closing: "9660",
        },
        {
            id: 2,
            code: "4000",
            name: "Sales revenue",
            type: "income",
            opening: "0",
            debit: "0",
            credit: "8400",
            closing: "-8400",
        },
        {
            id: 3,
            code: "2100",
            name: "Output VAT",
            type: "liability",
            opening: "0",
            debit: "0",
            credit: "1260",
            closing: "-1260",
        },
    ],
    general_ledger: [
        {
            id: 1,
            date: "2026-11-04",
            journal: "JRN-000015",
            account: "1100 Trade receivables",
            reference: "INVOICE-000005",
            description: "School ERP subscription",
            debit: "9660",
            credit: "0",
        },
        {
            id: 2,
            date: "2026-11-04",
            journal: "JRN-000015",
            account: "4000 Sales revenue",
            reference: "INVOICE-000005",
            description: "School ERP subscription",
            debit: "0",
            credit: "8400",
        },
    ],
    profit_loss: { income: "8400", expense: "0", profit: "8400" },
    balance_sheet: {
        assets: "9660",
        liabilities: "1260",
        equity_including_earnings: "8400",
        unclosed_earnings: "8400",
        difference: "0",
    },
    cash_flow: {
        opening: "1000",
        closing: "1000",
        movement: "0",
        exchange_effect: "0",
        categories: {
            operating: "0",
            investing: "0",
            financing: "0",
            none: "0",
        },
    },
};
const aging = {
    date: "2026-11-04",
    book: "live",
    parties: [
        {
            ...totals,
            key: "receivable:1",
            party_name: "Hande High School",
            role: "receivable",
            documents: 2,
        },
    ],
    totals: { receivable: totals, payable: zero },
    controls: [
        {
            role: "receivable",
            branch_id: 1,
            branch_name: "Harare · Head office",
            ledger: "9660",
            subledger: "9660",
            unallocated: "0",
        },
        {
            role: "payable",
            branch_id: 1,
            branch_name: "Harare · Head office",
            ledger: "0",
            subledger: "0",
            unallocated: "0",
        },
    ],
    aging: [
        {
            id: 5,
            number: "INVOICE-000005",
            kind: "invoice",
            party_name: "Hande High School",
            party_key: "receivable:1",
            role: "receivable",
            date: "2026-10-12",
            due_on: "2026-11-12",
            outstanding: "8460",
            days_overdue: 0,
            bucket: "current",
            branch_name: "Harare · Head office",
            original_currency: null,
            original_outstanding: null,
        },
        {
            id: 4,
            number: "INVOICE-000004",
            kind: "invoice",
            party_name: "Hande High School",
            party_key: "receivable:1",
            role: "receivable",
            date: "2026-08-12",
            due_on: "2026-09-12",
            outstanding: "1200",
            days_overdue: 53,
            bucket: "31–60",
            branch_name: "Harare · Head office",
            original_currency: null,
            original_outstanding: null,
        },
    ],
};
const fixture = {
    section: "reports",
    user: { id: 1, name: "Charles Matsika" },
    role: "Owner",
    permissions: ["*"],
    company: {
        name: "Betterlink Industries Private Limited",
        industry: "general",
        base_currency: "USD",
        accepted_currencies: ["USD"],
        settings: {},
    },
    branches: [
        {
            id: 1,
            name: "Harare · Head office",
            code: "HRE",
            active: true,
            warehouses: [],
        },
    ],
    modules: [
        "dashboard",
        "pos",
        "inventory",
        "purchasing",
        "sales",
        "backoffice",
        "accounting",
        "reports",
        "currencies",
        "hr",
        "payroll",
        "loyalty",
        "administration",
        "access",
        "branches",
    ].map((key, id) => ({
        id,
        key,
        name: key,
        enabled: true,
        available: true,
        settings: {},
    })),
    analytics: {
        filters: {
            from: "2026-11-01",
            to: "2026-11-04",
            branch_id: null,
            book: "live",
        },
        branches: [{ id: 1, name: "Harare · Head office" }],
        currency_totals: [],
        sales_count: 0,
        daily_sales: [],
        top_items: [],
        inventory: {
            variant_count: 12,
            value: "24150",
            quantity: "120",
            low_stock_count: 0,
            low_stock: [],
        },
        accounting: financial,
        business: {
            aging,
            sales: {
                net: "8400",
                tax: "1260",
                total: "9660",
                receipts: "0",
                documents: [],
            },
            procurement: {
                billed: "0",
                net: "0",
                tax: "0",
                paid: "0",
                received: "0",
                order_count: 0,
                orders: [],
                documents: [],
            },
        },
        company_name: "Betterlink Industries Private Limited",
        generated_at: "2026-11-04T14:00:00Z",
        base_currency: "USD",
        timezone: "Africa/Harare",
    },
};
test.beforeAll(async () => {
    const result = await build({
        stdin: {
            contents: `import React from 'react';import {createRoot} from 'react-dom/client';import {createInertiaApp} from '@inertiajs/react';import Workspace from './resources/js/pages/Workspace';createInertiaApp({page:{component:'Workspace',props:window.fixture,url:'/reports',version:null,clearHistory:false,encryptHistory:false},resolve:()=>Workspace,setup({el,App,props}){createRoot(el).render(<App {...props}/>);}});`,
            resolveDir: process.cwd(),
            loader: "tsx",
        },
        bundle: true,
        write: false,
        outfile: "reports-fixture.js",
        format: "iife",
        define: { "process.env.NODE_ENV": '"test"' },
    });
    bundle = result.outputFiles.find((f) => f.path.endsWith(".js"))!.text;
    const manifest = JSON.parse(
        readFileSync("public/build/manifest.json", "utf8"),
    );
    styles = manifest["resources/js/app.tsx"].css
        .map((p: string) => readFileSync("public/build/" + p, "utf8"))
        .join("\n");
});
test("report catalogue, debtor drill-down, filters, financial statements and print layout", async ({
    page,
}, info) => {
    const errors: string[] = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await page.route("**/report-preview", (route) =>
        route.fulfill({
            contentType: "text/html",
            body: `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${styles}</style></head><body><div id="app"></div><script>window.fixture=${JSON.stringify(fixture)}</script><script>${bundle.replaceAll("</script", "<\\/script")}</script></body></html>`,
        }),
    );
    await page.goto("/report-preview");
    await expect(
        page.getByRole("heading", { name: "Business overview", exact: true }),
    ).toBeVisible();
    if (info.project.name === "mobile") {
        await page
            .getByRole("button", { name: "Open navigation", exact: true })
            .click();
        await expect(
            page.getByRole("link", {
                name: "Accounting & finance",
                exact: true,
            }),
        ).toBeVisible();
        await page
            .getByRole("button", { name: "Close navigation", exact: true })
            .click();
    }
    await page.screenshot({
        path: `test-results/erp-overview-${info.project.name}.png`,
        fullPage: true,
    });
    await page
        .getByRole("button", { name: "Debtors ageing", exact: true })
        .click();
    await expect(
        page.getByRole("heading", { name: "Debtors ageing", exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText("9,660.00", { exact: true }).first(),
    ).toBeVisible();
    await page
        .getByRole("button", { name: "Hande High School", exact: true })
        .click();
    await expect(
        page.getByRole("button", { name: "Show all parties" }),
    ).toBeVisible();
    await page.getByLabel("Overdue balances only").check();
    await expect(
        page.getByRole("link", { name: "INVOICE-000005", exact: true }),
    ).toHaveCount(0);
    await expect(
        page.getByRole("link", { name: "INVOICE-000004", exact: true }),
    ).toBeVisible();
    await page.getByLabel("Overdue balances only").uncheck();
    await expect(
        page.getByRole("link", { name: "CSV", exact: true }),
    ).toHaveAttribute("href", /report=debtors/);
    await page.screenshot({
        path: `test-results/erp-debtors-${info.project.name}.png`,
        fullPage: true,
    });
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    await page
        .getByRole("button", { name: "Profit & loss", exact: true })
        .click();
    await expect(
        page.getByText("Total revenue", { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText("4000 · Sales revenue", { exact: true }),
    ).toBeVisible();
    await page
        .getByRole("button", { name: "General ledger", exact: true })
        .click();
    await page.getByLabel("Find a posting").fill("no-such-reference");
    await expect(
        page.getByText("No postings match these filters."),
    ).toBeVisible();
    await page
        .getByRole("button", { name: "Balance sheet", exact: true })
        .click();
    await page.emulateMedia({ media: "print" });
    await expect(page.locator(".sidebar")).not.toBeVisible();
    await expect(
        page.getByRole("heading", { name: "Balance sheet", exact: true }),
    ).toBeVisible();
    await page.screenshot({
        path: `test-results/erp-print-${info.project.name}.png`,
        fullPage: true,
    });
    expect(await page.locator(".report-paper").innerText()).not.toContain(
        "NaN",
    );
    expect(errors).toEqual([]);
});

test("accounting report workspace uses structured statements and the correct snapshot filters", async ({
    page,
}, info) => {
    const data = {
        ...fixture,
        section: "accounting",
        accounting: {
            setting: { currency: "USD", live_enabled: true },
            today: "2026-11-04",
            book: "live",
            branches: fixture.branches,
            warehouses: [],
            variants: [],
            accounts: financial.trial_balance.map((r) => ({
                ...r,
                report_group: r.type,
                is_cash: false,
                active: true,
            })),
            mappings: [],
            periods: [],
            journals: [],
            banks: [],
            statements: [],
            purchases: [],
        },
    };
    await page.route("**/accounting-preview", (route) =>
        route.fulfill({
            contentType: "text/html",
            body: `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${styles}</style><div id="app"></div><script>window.fixture=${JSON.stringify(data)}</script><script>${bundle.replaceAll("</script", "<\\/script")}</script>`,
        }),
    );
    await page.route("**/accounting/reports?**", (route) =>
        route.fulfill({
            json: {
                data: {
                    ...financial,
                    filters: {
                        book: "live",
                        from: "2026-11-01",
                        to: "2026-11-04",
                        branch_id: null,
                    },
                },
            },
        }),
    );
    await page.goto("/accounting-preview");
    await page
        .getByRole("button", { name: "Run reports", exact: true })
        .click();
    await expect(
        page.getByText("Net profit / (loss)", { exact: true }),
    ).toBeVisible();
    await page
        .getByRole("button", { name: "Trial balance", exact: true })
        .click();
    await expect(
        page.getByRole("columnheader", { name: "Period debit", exact: true }),
    ).toBeVisible();
    await page.screenshot({
        path: `test-results/erp-accounting-${info.project.name}.png`,
        fullPage: true,
    });
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    await page
        .getByRole("button", { name: "Balance sheet", exact: true })
        .click();
    await expect(
        page.getByText("Total equity including earnings", { exact: true }),
    ).toBeVisible();
});
