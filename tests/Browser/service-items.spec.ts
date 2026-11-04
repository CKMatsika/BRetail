import { test, expect } from "@playwright/test";
import { build } from "esbuild";
import { readFileSync } from "node:fs";

let bundle: string;
const styles = readFileSync("resources/css/app.css", "utf8").replace(
    /^@import.*$/gm,
    "",
);
test.beforeAll(async () => {
    const result = await build({
        stdin: {
            contents: `import React from 'react';import {createRoot} from 'react-dom/client';import Inventory from './resources/js/pages/Inventory';import Pos from './resources/js/pages/Pos';const props=window.fixture;createRoot(document.getElementById('root')).render(window.fixtureKind==='pos'?<Pos pos={props} permissions={['*']}/>:<Inventory {...props}/>);`,
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
const variant = (id: number, sku: string) => ({
    id,
    product_id: id,
    name: "Standard",
    sku,
    barcode: null,
    cost_price: "0",
    selling_price: "100",
    wholesale_price: "100",
    reorder_level: "0",
    minimum_stock: "0",
    maximum_stock: null,
    active: true,
    attributes: {},
});
const product = (id: number, name: string, type: string) => ({
    id,
    name,
    item_type: type,
    description: null,
    brand: null,
    unit: "each",
    tax_class: "zero",
    warranty_months: 0,
    department_id: null,
    category_id: null,
    subcategory_id: null,
    active: true,
    attributes: {},
    variants: [variant(id, id === 1 ? "ERP" : "KEYBOARD")],
});

test("inventory separates services from stock and provides an item type selector", async ({
    page,
}, info) => {
    const data = {
        permissions: ["*"],
        branches: [{ id: 1, name: "Main" }],
        baseCurrency: "USD",
        warehouses: [{ id: 1, name: "Main", branch_id: 1 }],
        classifications: [],
        products: [
            product(1, "ERP licence", "service"),
            product(2, "Keyboard", "stock"),
        ],
        balances: [],
        counts: [],
        movements: [],
    };
    await page.route("**/service-ui", (route) =>
        route.fulfill({
            contentType: "text/html",
            body: `<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>:root{--surface:#fff;--muted:#78847e;--line:#dce5df;--ink:#223b30;--bg:#f6f8f6}${styles}</style><main style="padding:20px"><div id="root"></div></main><script>window.fixture=${JSON.stringify(data)}</script><script>${bundle.replaceAll("</script", "<\\/script")}</script>`,
        }),
    );
    const errors: string[] = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto("/service-ui");
    await expect(
        page.getByRole("heading", { name: "Products & services catalogue" }),
    ).toBeVisible();
    await page
        .getByRole("button", { name: "Add product", exact: true })
        .click();
    await page.getByLabel("Item type").selectOption("service");
    await expect(
        page.getByText(
            "For software, licences, consulting and other services.",
            { exact: false },
        ),
    ).toBeVisible();
    await page.screenshot({
        path: `test-results/service-selector-${info.project.name}.png`,
        fullPage: true,
    });
    await page.getByRole("button", { name: "Close", exact: true }).click();
    await page
        .getByRole("button", { name: "Stock counts", exact: true })
        .click();
    await expect(
        page.getByRole("option", {
            name: "KEYBOARD · Standard",
            includeHidden: true,
        }),
    ).toHaveCount(1);
    await expect(
        page.getByRole("option", {
            name: "ERP · Standard",
            includeHidden: true,
        }),
    ).toHaveCount(0);
    expect(errors).toEqual([]);
});

test("POS allows a service with no stock while blocking an unstocked physical product", async ({
    page,
}, info) => {
    const variants = [
        {
            ...variant(1, "ERP"),
            product_name: "ERP licence",
            item_type: "service",
            brand: null,
            department_id: null,
        },
        {
            ...variant(2, "KEYBOARD"),
            product_name: "Keyboard",
            item_type: "stock",
            brand: null,
            department_id: null,
        },
    ];
    const data = {
        training_available: true,
        live_available: false,
        current_user_id: 1,
        currency: "USD",
        discount_limit: "10",
        daily: {
            date: "2026-11-04",
            timezone: "Africa/Harare",
            sale_count: 0,
            return_count: 0,
            gross_sales: "0",
            tax: "0",
            refunds: "0",
            net_recorded: "0",
        },
        warehouses: [{ id: 1, name: "Main", branch_id: 1 }],
        departments: [],
        variants,
        stock: [],
        customers: [],
        loyalty_available: false,
        holds: [],
        sessions: [],
        own_session: null,
        sales: [],
    };
    await page.route("**/service-ui", (route) =>
        route.fulfill({
            contentType: "text/html",
            body: `<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>:root{--surface:#fff;--muted:#78847e;--line:#dce5df;--ink:#223b30;--bg:#f6f8f6}${styles}</style><main style="padding:20px"><div id="root"></div></main><script>window.fixtureKind='pos';window.fixture=${JSON.stringify(data)}</script><script>${bundle.replaceAll("</script", "<\\/script")}</script>`,
        }),
    );
    await page.route("**/pos/quote", (route) =>
        route.fulfill({
            json: {
                data: {
                    currency: "USD",
                    items: [
                        {
                            ...variant(1, "ERP"),
                            variant_id: 1,
                            description: "ERP licence",
                            quantity: "1",
                            unit_price: "100",
                            net: "100",
                            tax: "0",
                            total: "100",
                        },
                    ],
                    subtotal: "100",
                    discount: "0",
                    tax: "0",
                    total: "100",
                    quote_token: "fixture",
                },
            },
        }),
    );
    const errors: string[] = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto("/service-ui");
    const service = page
        .getByRole("button")
        .filter({ hasText: "Service · no stock required" });
    await expect(service).toBeEnabled();
    await expect(
        page.locator("button.pos-product").filter({ hasText: "Keyboard" }),
    ).toBeDisabled();
    await service.click();
    await expect(
        page.locator(".pos-cart-line").filter({ hasText: "ERP licence" }),
    ).toBeVisible();
    await page.screenshot({
        path: `test-results/service-pos-${info.project.name}.png`,
        fullPage: true,
    });
    expect(errors).toEqual([]);
});
