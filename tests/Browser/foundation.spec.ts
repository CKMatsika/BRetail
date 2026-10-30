import { test, expect } from "@playwright/test";
test("owner onboards, creates branch, saves preferences, and uses mobile navigation", async ({
    page,
}, testInfo) => {
    const email = `owner-${Date.now()}-${testInfo.project.name}@example.test`;
    await page.goto("/register");
    await page.getByLabel("Your name", { exact: true }).fill("Alex Moyo");
    await page.getByLabel("Email address", { exact: true }).fill(email);
    await page
        .getByLabel("Password · 12+ characters, letters and numbers")
        .fill("SecurePassword123");
    await page.getByLabel("Confirm password").fill("SecurePassword123");
    await page.getByRole("button", { name: "Continue" }).click();
    await page
        .getByLabel("Business name", { exact: true })
        .fill("Little Steps Retail");
    await page
        .getByRole("combobox", { name: "Business type", exact: true })
        .selectOption("apparel");
    await page.getByRole("button", { name: "Continue" }).click();
    await page.getByRole("button", { name: "Create my workspace" }).click();
    await expect(page).toHaveURL("/dashboard");
    await expect(
        page.getByRole("heading", { name: "Welcome back, Alex." }),
    ).toBeVisible();
    await page.screenshot({
        path: `test-results/dashboard-${testInfo.project.name}.png`,
        fullPage: true,
    });
    const navigate = async (name: string) => {
        const open = page.getByRole("button", {
            name: "Open navigation",
            exact: true,
        });
        if (await open.isVisible()) await open.click();
        await page.getByRole("link", { name, exact: true }).first().click();
    };
    await navigate("Branches & warehouses");
    await page.getByRole("button", { name: "Add branch", exact: true }).click();
    const dialog = page.getByRole("dialog");
    await dialog.getByLabel("name", { exact: true }).fill("Bulawayo");
    await dialog.getByLabel("code", { exact: true }).fill("BYO");
    await dialog.getByRole("button", { name: "Save branch" }).click();
    await expect(page.getByText("Branch and warehouse created.")).toBeVisible();
    await expect(
        page.getByRole("cell", { name: "Bulawayo", exact: false }),
    ).toBeVisible();
    await navigate("Modules");
    await page
        .locator(".module-card")
        .filter({
            has: page.getByRole("heading", { name: "Inventory", exact: true }),
        })
        .getByRole("checkbox")
        .click();
    await expect(page.getByText("Module preference saved.")).toBeVisible();
    await navigate("Team & access");
    await page.getByRole("button", { name: "Add team member" }).click();
    await dialog.getByLabel("name", { exact: true }).fill("Pat Cashier");
    await dialog.getByLabel("email", { exact: true }).fill("cashier-" + email);
    await dialog
        .getByLabel("password", { exact: true })
        .fill("SecurePassword123");
    await dialog.getByLabel("Main branch", { exact: true }).check();
    await dialog.getByRole("button", { name: "Save team member" }).click();
    await expect(page.getByText("Team member created.")).toBeVisible();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole("button", { name: "Open navigation" }).click();
    await page.getByRole("button", { name: "Sign out" }).click();
    await expect(page).toHaveURL("/login");
    await page
        .getByLabel("Email address", { exact: true })
        .fill("cashier-" + email);
    await page
        .getByLabel("Password", { exact: true })
        .fill("SecurePassword123");
    await page.getByRole("button", { name: "Sign in", exact: true }).click();
    await expect(page).toHaveURL("/dashboard");
    await page.getByRole("button", { name: "Open navigation" }).click();
    await expect(
        page.getByRole("link", { name: "Company settings", exact: true }),
    ).toHaveCount(0);
    await page.getByRole("button", { name: "Close navigation" }).click();
    await expect(
        page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).resolves.toBeTruthy();
    await page.goto("/company");
    await expect(page.getByText("403", { exact: true })).toBeVisible();
});
