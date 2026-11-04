import { test, expect } from "@playwright/test";
test("inventory catalogue, opening stock, adjustment, count and CSV export", async ({
    page,
}, info) => {
    const email = `inventory-${Date.now()}-${info.project.name}@example.test`;
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
        .fill("Little Steps Inventory");
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
    await page
        .getByRole("button", { name: "Enable inventory", exact: true })
        .click();
    await expect(page.getByText("Module preference saved.")).toBeVisible();
    await navigate("Inventory");
    await expect(
        page.getByRole("heading", { name: "Products & services catalogue" }),
    ).toBeVisible();
    await page.getByRole("button", { name: "Setup", exact: true }).click();
    await page
        .getByLabel("Classification name", { exact: true })
        .fill("Clothing");
    await page
        .getByRole("button", { name: "Add classification", exact: true })
        .click();
    await expect(page.getByText("Classification created.")).toBeVisible();
    await page.getByRole("button", { name: "Catalogue", exact: true }).click();
    await page
        .getByRole("button", { name: "Add product", exact: true })
        .click();
    const dialog = page.getByRole("dialog");
    await dialog
        .getByLabel("Product name", { exact: true })
        .fill("Cotton Baby Romper");
    await dialog
        .getByRole("combobox", { name: "department", exact: true })
        .selectOption({ label: "Clothing" });
    await dialog
        .getByRole("button", { name: "Save product", exact: true })
        .click();
    await expect(page.getByText("Product saved.")).toBeVisible();
    await page
        .getByRole("button", { name: "Add variant", exact: true })
        .click();
    await dialog.getByLabel("name", { exact: true }).fill("Pink / 0–3 months");
    await dialog.getByLabel("sku", { exact: true }).fill("ROMPER-PINK-03");
    await dialog.getByLabel("cost price", { exact: true }).fill("10");
    await dialog.getByLabel("selling price", { exact: true }).fill("18.50");
    await dialog.getByLabel("Attribute name", { exact: true }).fill("Size");
    await dialog
        .getByLabel("Attribute value", { exact: true })
        .fill("0–3 months");
    await dialog
        .getByRole("button", { name: "Save variant", exact: true })
        .click();
    await expect(page.getByText("Variant saved.")).toBeVisible();
    await expect(
        page.getByText("ROMPER-PINK-03", { exact: true }),
    ).toBeVisible();
    await page.screenshot({
        path: `test-results/inventory-catalogue-${info.project.name}.png`,
        fullPage: true,
    });
    await page
        .getByRole("button", { name: "Stock ledger", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Post movement", exact: true })
        .click();
    await dialog.getByLabel("reference", { exact: true }).fill("OPEN-1");
    await dialog
        .getByLabel("reason", { exact: true })
        .fill("Initial inventory");
    await dialog
        .getByLabel("Quantity · negative removes stock", { exact: true })
        .fill("10");
    await dialog.getByLabel("Incoming unit cost", { exact: true }).fill("10");
    await dialog
        .getByRole("button", { name: "Post stock movement", exact: true })
        .click();
    await expect(page.getByText("Stock document posted.")).toBeVisible();
    await expect(
        page.getByRole("cell", { name: "10.000", exact: true }),
    ).toHaveCount(2);
    await page
        .getByRole("button", { name: "Post movement", exact: true })
        .click();
    await dialog
        .getByRole("combobox", { name: "Operation", exact: true })
        .selectOption("adjustment");
    await dialog.getByLabel("reference", { exact: true }).fill("DAMAGE-1");
    await dialog
        .getByLabel("reason", { exact: true })
        .fill("Damaged during handling");
    await dialog
        .getByLabel("Quantity · negative removes stock", { exact: true })
        .fill("-2");
    await dialog
        .getByRole("button", { name: "Post stock movement", exact: true })
        .click();
    await expect(page.getByText("Stock document posted.")).toBeVisible();
    await expect(
        page.getByRole("cell", { name: "8.000", exact: true }),
    ).toBeVisible();
    await page
        .getByRole("button", { name: "Stock counts", exact: true })
        .click();
    await page.getByLabel("Count reference", { exact: true }).fill("COUNT-1");
    await page
        .getByRole("button", { name: "Start count", exact: true })
        .click();
    await expect(page.getByText("Count snapshot created.")).toBeVisible();
    await page
        .getByRole("button", { name: "Enter count", exact: true })
        .click();
    await dialog
        .getByLabel("ROMPER-PINK-03 · expected 8.000", { exact: true })
        .fill("7");
    await dialog
        .getByRole("button", { name: "Post count variances", exact: true })
        .click();
    await expect(page.getByText("Stock count posted.")).toBeVisible();
    await page
        .getByRole("button", { name: "Stock ledger", exact: true })
        .click();
    await expect(
        page.getByRole("cell", { name: "7.000", exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole("cell", { name: "70.0000", exact: true }),
    ).toBeVisible();
    await page
        .getByRole("button", { name: "Reconcile Main warehouse", exact: true })
        .click();
    await expect(
        page.getByText("Ledger and balances reconcile."),
    ).toBeVisible();
    await page.screenshot({
        path: `test-results/inventory-ledger-${info.project.name}.png`,
        fullPage: true,
    });
    await page.getByRole("button", { name: "Catalogue", exact: true }).click();
    const download = page.waitForEvent("download");
    await page.getByRole("link", { name: "Export CSV", exact: true }).click();
    expect((await download).suggestedFilename()).toBe("inventory-catalog.csv");
    await page.getByRole("button", { name: "Import", exact: true }).click();
    await page
        .getByLabel("Catalogue CSV", { exact: true })
        .setInputFiles({
            name: "catalog.csv",
            mimeType: "text/csv",
            buffer: Buffer.from(
                "product_name,variant_name,sku,barcode,department,category,subcategory,brand,unit,cost_price,selling_price,wholesale_price,reorder_level,attributes_json\nUSB Cable,Standard,USB-CABLE,,,,,Acme,each,2.50,5.00,4.00,3,{}\n",
            ),
        });
    await page
        .getByRole("button", { name: "Validate and import", exact: true })
        .click();
    await expect(
        page.getByText("Imported 1 variants atomically."),
    ).toBeVisible();
    await page.getByRole("button", { name: "Catalogue", exact: true }).click();
    await expect(page.getByText("USB-CABLE", { exact: true })).toBeVisible();
    await expect(
        page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).resolves.toBeTruthy();
});
