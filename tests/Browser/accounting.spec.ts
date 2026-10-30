import { test, expect } from "@playwright/test";
import { randomUUID } from "node:crypto";
test("accounting initializes, activates live books, posts POS and reconciles bank", async ({
    page,
}, info) => {
    await page.goto("/register");
    await page.getByLabel("Your name", { exact: true }).fill("Finance Owner");
    await page
        .getByLabel("Email address", { exact: true })
        .fill(`finance-${Date.now()}-${info.project.name}@example.test`);
    await page
        .getByLabel("Password · 12+ characters, letters and numbers")
        .fill("SecurePassword123");
    await page.getByLabel("Confirm password").fill("SecurePassword123");
    await page.getByRole("button", { name: "Continue", exact: true }).click();
    await page
        .getByLabel("Business name", { exact: true })
        .fill("Finance Validation Shop");
    await page.getByRole("button", { name: "Continue", exact: true }).click();
    await page
        .getByRole("button", { name: "Create my workspace", exact: true })
        .click();
    await expect(page).toHaveURL("/dashboard");
    const navigate = async (name: string) => {
        const menu = page.getByRole("button", {
            name: "Open navigation",
            exact: true,
        });
        if (await menu.isVisible()) await menu.click();
        await page.getByRole("link", { name, exact: true }).first().click();
    };
    await navigate("Modules");
    for (const name of [
        "Enable inventory",
        "Enable accounting & finance",
        "Enable point of sale",
    ]) {
        await page.getByRole("button", { name, exact: true }).click();
        await expect(
            page.getByRole("button", {
                name: name.replace("Enable", "Disable"),
                exact: true,
            }),
        ).toBeVisible();
    }
    await navigate("Accounting & finance");
    await page
        .getByRole("button", { name: "Initialize accounting", exact: true })
        .click();
    await expect(
        page.getByRole("button", { name: "Run reports", exact: true }),
    ).toBeVisible();
    await page
        .getByRole("button", { name: "Configuration", exact: true })
        .click();
    await page
        .getByLabel(
            "I verified the current opening stock quantities and values",
        )
        .check();
    await page
        .getByLabel(
            "I confirm the base currency uses two decimal monetary amounts",
        )
        .check();
    await page
        .getByRole("button", { name: "Activate live retail", exact: true })
        .click();
    await expect(
        page.getByText("Live retail is activated.", { exact: false }),
    ).toBeVisible();
    await page.getByLabel("View book").selectOption("live");
    const token = (await page.context().cookies()).find(
        (c) => c.name === "XSRF-TOKEN",
    )!;
    const headers = {
        "X-XSRF-TOKEN": decodeURIComponent(token.value),
        Accept: "application/json",
    };
    const post = async (url: string, data: unknown) => {
        const r = await page.request.post(url, { headers, data });
        expect(r.ok(), await r.text()).toBeTruthy();
        return (await r.json()).data;
    };
    const data = (await (await page.request.get("/accounting/data")).json())
        .data;
    const warehouse = data.warehouses[0].id;
    const account = (role: string) =>
        String(
            data.mappings.find((m: { role: string }) => m.role === role)
                .account_id,
        );
    await page.getByRole("button", { name: "Cashbook", exact: true }).click();
    const cashForm = page.locator("form").filter({
        has: page.getByRole("heading", {
            name: "Cashbook receipt, payment or transfer",
            exact: true,
        }),
    });
    await cashForm.getByLabel("Reference", { exact: true }).fill("OWNER-FUNDS");
    await cashForm
        .getByLabel("Description", { exact: true })
        .fill("Verified owner capital");
    await cashForm
        .getByLabel("Cash flow", { exact: true })
        .selectOption("financing");
    await cashForm
        .getByLabel("Cash / bank account", { exact: true })
        .selectOption(account("cash_safe"));
    await cashForm
        .getByLabel("Counterpart account", { exact: true })
        .selectOption(account("opening_equity"));
    await cashForm
        .getByLabel("Amount (two decimals)", { exact: true })
        .fill("100");
    await cashForm
        .getByRole("button", { name: "Post cashbook entry", exact: true })
        .click();
    await expect(cashForm.getByRole("status")).toHaveText(
        "Saved successfully.",
    );
    const product = await post("/inventory/products", {
        name: "Cotton Shirt",
        unit: "each",
        tax_class: "zero",
        warranty_months: 0,
        active: true,
        attributes: [],
    });
    const variant = await post(`/inventory/products/${product.id}/variants`, {
        name: "Green",
        sku: "FIN-SHIRT",
        barcode: "600987654",
        cost_price: "5",
        selling_price: "10",
        wholesale_price: "10",
        minimum_stock: "0",
        reorder_level: "0",
        active: true,
        attributes: [],
    });
    await post("/inventory/movements", {
        request_id: randomUUID(),
        warehouse_id: warehouse,
        type: "opening",
        reference: "LIVE-OPEN",
        reason: "Verified stock",
        lines: [{ variant_id: variant.id, quantity: "10", unit_cost: "5" }],
    });
    await navigate("Point of sale");
    await expect(
        page.getByText("LIVE ACCOUNTING", { exact: true }),
    ).toBeVisible();
    await page.getByRole("button", { name: "Open shift", exact: true }).click();
    let dialog = page.getByRole("dialog");
    await dialog.getByLabel("Opening cash float", { exact: true }).fill("20");
    await dialog
        .getByRole("button", { name: "Open shift", exact: true })
        .click();
    await expect(dialog).toHaveCount(0);
    await page
        .getByRole("textbox", { name: "Search or scan barcode", exact: true })
        .fill("600987654");
    await page
        .getByRole("textbox", { name: "Search or scan barcode", exact: true })
        .press("Enter");
    await expect(
        page.getByRole("button", { name: "Take payment", exact: true }),
    ).toBeEnabled();
    await page
        .getByRole("button", { name: "Take payment", exact: true })
        .click();
    dialog = page.getByRole("dialog");
    await dialog.getByLabel("Amount", { exact: true }).fill("10");
    await dialog
        .getByRole("button", { name: "Complete sale", exact: true })
        .click();
    await expect(
        page.getByText("RETAIL RECEIPT", { exact: true }),
    ).toBeVisible();
    const popup = page.waitForEvent("popup");
    await page
        .getByRole("link", { name: "Print / reprint", exact: true })
        .click();
    const receipt = await popup;
    await expect(
        receipt.getByText("RETAIL RECEIPT", { exact: false }),
    ).toBeVisible();
    await expect(
        receipt.getByText("Posted to the live accounting book.", {
            exact: false,
        }),
    ).toBeVisible();
    await receipt.close();
    await page
        .getByRole("dialog")
        .getByRole("button", { name: "Close", exact: true })
        .click();
    await navigate("Accounting & finance");
    await page.getByRole("button", { name: "Reports", exact: true }).click();
    await page
        .getByRole("button", { name: "Run reports", exact: true })
        .click();
    await expect(
        page.getByText("Balance difference 0.0000", { exact: false }),
    ).toBeVisible();
    await expect(
        page.getByText("Ledger 45.0000 · Stock 45.0000 · Difference 0.0000", {
            exact: true,
        }),
    ).toBeVisible();
    await page.screenshot({
        path: `test-results/accounting-reports-${info.project.name}.png`,
        fullPage: true,
    });
    await expect(
        page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    ).resolves.toBeTruthy();
    const j = await post("/accounting/journals", {
        request_id: randomUUID(),
        book: "live",
        branch_id: data.branches[0].id,
        date: data.today,
        reference: "BANK-CAPITAL",
        description: "Verified bank capital",
        cashflow: "financing",
        lines: [
            { account_id: account("bank"), debit: "50", credit: "0" },
            { account_id: account("opening_equity"), debit: "0", credit: "50" },
        ],
    });
    await page.getByRole("button", { name: "Banking", exact: true }).click();
    const bankForm = page.locator("form").filter({
        has: page.getByRole("heading", {
            name: "Import bank statement CSV",
            exact: true,
        }),
    });
    await bankForm
        .getByLabel("Closing statement balance", { exact: true })
        .fill("50");
    await bankForm
        .getByLabel("CSV contents", { exact: true })
        .fill(
            `date,reference,description,amount\n${data.today},BANK-CAPITAL,Deposit,50`,
        );
    await bankForm
        .getByLabel("I verified the opening ledger baseline / prior statement")
        .check();
    await bankForm
        .getByRole("button", { name: "Import statement", exact: true })
        .click();
    await expect(bankForm.getByRole("status")).toHaveText(
        "Saved successfully.",
    );
    await page
        .getByRole("button", { name: /^Open statement / })
        .first()
        .click();
    await page
        .getByRole("radio", { name: /^Statement line / })
        .first()
        .check();
    await page
        .locator(".finance-card")
        .filter({
            has: page.getByRole("heading", { name: /^Reconcile statement / }),
        })
        .getByRole("checkbox")
        .first()
        .check();
    await page
        .getByRole("button", { name: "Match selected entries", exact: true })
        .click();
    await expect(
        page.getByRole("button", { name: "Unmatch", exact: true }),
    ).toBeVisible();
    await page
        .getByRole("button", { name: "Finalize reconciliation", exact: true })
        .click();
    await expect(
        page.getByRole("heading", { name: /Reconcile statement .*closed/ }),
    ).toBeVisible();
    await expect(
        page.getByText("Outstanding at finalization: 0.0000", { exact: true }),
    ).toBeVisible();
    await page.screenshot({
        path: `test-results/accounting-bank-${info.project.name}.png`,
        fullPage: true,
    });
});
