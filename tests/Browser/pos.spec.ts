import { test, expect } from "@playwright/test";
import { randomUUID } from "node:crypto";
test("training POS scans, holds, splits payment, prints, returns and closes shift", async ({
    page,
}, info) => {
    const email = `pos-${Date.now()}-${info.project.name}@example.test`;
    await page.goto("/register");
    await page.getByLabel("Your name", { exact: true }).fill("Alex Moyo");
    await page.getByLabel("Email address", { exact: true }).fill(email);
    await page
        .getByLabel("Password · 12+ characters, letters and numbers")
        .fill("SecurePassword123");
    await page.getByLabel("Confirm password").fill("SecurePassword123");
    await page.getByRole("button", { name: "Continue", exact: true }).click();
    await page
        .getByLabel("Business name", { exact: true })
        .fill("Little Steps POS Training");
    await page.getByRole("button", { name: "Continue", exact: true }).click();
    await page
        .getByRole("button", { name: "Create my workspace", exact: true })
        .click();
    await expect(page).toHaveURL("/dashboard");
    const navigate = async (name: string) => {
        const m = page.getByRole("button", {
            name: "Open navigation",
            exact: true,
        });
        if (await m.isVisible()) await m.click();
        await page.getByRole("link", { name, exact: true }).first().click();
    };
    await navigate("Modules");
    await page
        .getByRole("button", { name: "Enable inventory", exact: true })
        .click();
    await expect(page.getByText("Module preference saved.")).toBeVisible();
    await page
        .getByRole("button", { name: "Enable point of sale", exact: true })
        .click();
    await expect(
        page.getByRole("button", {
            name: "Disable point of sale",
            exact: true,
        }),
    ).toBeVisible();
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
    await navigate("Point of sale");
    const warehouse = Number(
        await page
            .getByRole("combobox", { name: "Selling warehouse", exact: true })
            .inputValue(),
    );
    for (const item of [
        {
            name: "Cotton Baby Romper",
            variant: "Pink / 0–3 months",
            sku: "ROMPER-PINK",
            barcode: "600123456",
            cost: "10",
            price: "20",
        },
        {
            name: "Everyday Baby Socks",
            variant: "White / 3-pack",
            sku: "SOCKS-WHITE",
            barcode: "600123457",
            cost: "3",
            price: "6.50",
        },
        {
            name: "Soft Cotton Blanket",
            variant: "Sage green",
            sku: "BLANKET-SAGE",
            barcode: "600123458",
            cost: "8",
            price: "15",
        },
    ]) {
        const product = await post("/inventory/products", {
            name: item.name,
            brand: "Little Steps",
            unit: "each",
            tax_class: "standard",
            warranty_months: 0,
            active: true,
            attributes: [],
        });
        const variant = await post(
            "/inventory/products/" + product.id + "/variants",
            {
                name: item.variant,
                sku: item.sku,
                barcode: item.barcode,
                cost_price: item.cost,
                selling_price: item.price,
                wholesale_price: "0",
                reorder_level: "2",
                minimum_stock: "0",
                active: true,
                attributes: [],
            },
        );
        await post("/inventory/movements", {
            request_id: randomUUID(),
            warehouse_id: warehouse,
            type: "opening",
            reference: "TRAIN-OPEN",
            reason: "Isolated browser training data",
            lines: [
                {
                    variant_id: variant.id,
                    quantity: "20",
                    unit_cost: item.cost,
                },
            ],
        });
    }
    await page.reload();
    await expect(
        page.getByText("TRAINING MODE", { exact: true }),
    ).toBeVisible();
    await page.getByRole("button", { name: "Open shift", exact: true }).click();
    let dialog = page.getByRole("dialog");
    await dialog.getByLabel("Opening cash float", { exact: true }).fill("50");
    await dialog
        .getByRole("button", { name: "Open shift", exact: true })
        .click();
    await expect(dialog).toHaveCount(0);
    await page
        .getByRole("button", { name: "Quick customer", exact: true })
        .click();
    dialog = page.getByRole("dialog");
    await dialog.getByLabel("Name", { exact: true }).fill("Jane Customer");
    await dialog.getByLabel("Phone", { exact: true }).fill("0770000000");
    await dialog
        .getByRole("button", { name: "Save customer", exact: true })
        .click();
    await expect(dialog).toHaveCount(0);
    await page
        .getByRole("textbox", { name: "Search or scan barcode", exact: true })
        .fill("600123456");
    await page
        .getByRole("textbox", { name: "Search or scan barcode", exact: true })
        .press("Enter");
    await expect(
        page.getByRole("button", { name: "Take payment", exact: true }),
    ).toBeEnabled();
    await page.getByRole("button", { name: "Hold sale", exact: true }).click();
    dialog = page.getByRole("dialog");
    await dialog
        .getByLabel("Sale label", { exact: true })
        .fill("Waiting for Jane");
    await dialog
        .getByRole("button", { name: "Hold sale", exact: true })
        .click();
    await expect(dialog).toHaveCount(0);
    await page
        .getByRole("button", { name: "Waiting for Jane", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Increase ROMPER-PINK", exact: true })
        .click();
    await expect(
        page.getByRole("button", { name: "Take payment", exact: true }),
    ).toBeEnabled();
    await page.screenshot({
        path: `test-results/pos-register-${info.project.name}.png`,
        fullPage: true,
    });
    await page
        .getByRole("button", { name: "Take payment", exact: true })
        .click();
    dialog = page.getByRole("dialog");
    await dialog.getByLabel("Amount", { exact: true }).fill("30");
    await dialog
        .getByRole("button", { name: "Add split payment", exact: true })
        .click();
    await dialog.getByLabel("Amount", { exact: true }).nth(1).fill("20");
    await dialog
        .getByLabel("External payment reference", { exact: true })
        .fill("CARD-TRAIN-001");
    await page.screenshot({
        path: `test-results/pos-payment-${info.project.name}.png`,
        fullPage: true,
    });
    await dialog
        .getByRole("button", { name: "Complete training sale", exact: true })
        .click();
    await expect(page.getByText(/Cash change 10.00/)).toBeVisible();
    const print = page.waitForEvent("popup");
    await page
        .getByRole("link", { name: "Print / reprint", exact: true })
        .click();
    const receipt = await print;
    await expect(receipt.getByText("TRAINING RECEIPT")).toBeVisible();
    await expect(receipt.getByText(/NOT A TAX INVOICE/)).toBeVisible();
    await expect(
        receipt.getByText("Jane Customer", { exact: false }),
    ).toBeVisible();
    await receipt.screenshot({
        path: `test-results/pos-receipt-${info.project.name}.png`,
        fullPage: true,
    });
    await receipt.close();
    await page
        .getByRole("button", { name: "Return items", exact: true })
        .click();
    dialog = page.getByRole("dialog");
    await dialog
        .getByLabel("ROMPER-PINK · returnable 2.000", { exact: true })
        .fill("1");
    await expect(dialog.getByText("USD 20.00", { exact: true })).toBeVisible();
    await dialog
        .getByRole("textbox", { name: "Return reason", exact: true })
        .fill("Customer chose a different size");
    await dialog.getByLabel("Amount", { exact: true }).fill("20");
    await dialog
        .getByRole("button", { name: "Post training return", exact: true })
        .click();
    await expect(dialog).toHaveCount(0);
    await page
        .getByRole("button", { name: "Cashier shifts", exact: true })
        .click();
    await expect(page.getByText(/1 sales \/ 1 returns/)).toBeVisible();
    await page
        .getByRole("button", { name: "Close shift", exact: true })
        .click();
    dialog = page.getByRole("dialog");
    await dialog.getByLabel("Counted cash", { exact: true }).fill("50");
    await dialog
        .getByRole("button", { name: "Close shift", exact: true })
        .click();
    await expect(dialog).toHaveCount(0);
    await expect(page.getByText("closed", { exact: true })).toBeVisible();
    await page.screenshot({
        path: `test-results/pos-shift-${info.project.name}.png`,
        fullPage: true,
    });
    await expect(
        page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    ).resolves.toBeTruthy();
});
