import React, { useState } from "react";
import axios from "axios";
import { router } from "@inertiajs/react";
import { Copy, MonitorSmartphone, ShieldCheck, Smartphone, XCircle } from "lucide-react";

type DeviceRow = {
    id: number;
    device_uuid: string;
    name: string;
    platform: string;
    user: string;
    branch: string;
    last_seen_at: string | null;
    revoked_at: string | null;
};

export default function DeviceManager({ devices, branches }: { devices: DeviceRow[]; branches: { id: number; name: string }[] }) {
    const [name, setName] = useState("");
    const [platform, setPlatform] = useState("android");
    const [branchId, setBranchId] = useState(branches[0]?.id ?? 0);
    const [secret, setSecret] = useState("");
    const [notice, setNotice] = useState("");
    const [busy, setBusy] = useState(false);

    const enroll = async (event: React.FormEvent) => {
        event.preventDefault();
        if (busy) return;
        setBusy(true);
        setNotice("");
        try {
            const response = await axios.post("/devices", { device_uuid: crypto.randomUUID(), branch_id: branchId, name, platform });
            setSecret(response.data.data.token);
            setName("");
            setNotice("Device enrolled. Copy the credential now; it is shown once.");
            router.reload({ only: ["devices"] });
        } catch (error) {
            setNotice(axios.isAxiosError(error) ? Object.values(error.response?.data?.errors ?? { message: error.response?.data?.message ?? error.message }).flat().join(" ") : String(error));
        } finally {
            setBusy(false);
        }
    };

    const revoke = async (device: DeviceRow) => {
        if (!window.confirm(`Revoke access for ${device.name}? The device credential will stop working immediately.`)) return;
        try {
            await axios.post(`/devices/${device.id}/revoke`);
            setNotice(`${device.name} access revoked.`);
            router.reload({ only: ["devices"] });
        } catch (error) {
            setNotice(axios.isAxiosError(error) ? error.response?.data?.message ?? error.message : String(error));
        }
    };

    return <section className="content-section device-manager">
        <div className="section-heading"><div><h2>Enrolled devices</h2><p>Devices are assigned to a user and branch. Revoking one disables its credential immediately.</p></div></div>
        <div className="notice"><ShieldCheck size={17} /> Device credentials can report connection status only. They cannot access business data or create transactions.</div>
        <form className="panel device-enroll-form" onSubmit={enroll}>
            <h3>Enroll a device</h3>
            <div className="form-grid">
                <label className="field">Device name<input required maxLength={120} value={name} onChange={event => setName(event.target.value)} placeholder="Front counter tablet" /></label>
                <label className="field">Assigned branch<select required value={branchId} onChange={event => setBranchId(Number(event.target.value))}>{branches.map(branch => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
                <label className="field">Platform<select value={platform} onChange={event => setPlatform(event.target.value)}><option value="android">Android</option><option value="ios">iOS</option><option value="windows">Windows</option><option value="macos">macOS</option><option value="other">Other</option></select></label>
            </div>
            <button className="primary" disabled={busy || !branches.length || !name.trim()}><Smartphone size={16} /> {busy ? "Enrolling…" : "Enroll device"}</button>
        </form>
        {notice && <p className="notice" role="status">{notice}</p>}
        {secret && <div className="panel device-token-panel"><h3>One-time device credential</h3><p>Keep this credential secure. It can report device status, but it cannot access business data or make transactions.</p><code>{secret}</code><button className="secondary" onClick={async () => { await navigator.clipboard.writeText(secret); setNotice("Credential copied to clipboard."); }}><Copy size={15} /> Copy credential</button></div>}
        <div className="panel table-wrap"><table><thead><tr><th>Device</th><th>Assigned user</th><th>Branch</th><th>Last heartbeat</th><th>Access</th><th /></tr></thead><tbody>
            {devices.map(device => <tr key={device.id}><td><strong><MonitorSmartphone size={15} /> {device.name}</strong><small>{device.platform} · {device.device_uuid.slice(0, 8)}</small></td><td>{device.user}</td><td>{device.branch}</td><td>{device.last_seen_at ? new Date(device.last_seen_at).toLocaleString() : "Not connected"}</td><td>{device.revoked_at ? "Revoked" : "Active"}</td><td>{!device.revoked_at && <button className="secondary small" onClick={() => revoke(device)}><XCircle size={14} /> Revoke</button>}</td></tr>)}
            {!devices.length && <tr><td colSpan={6} className="empty-state">No devices are enrolled yet.</td></tr>}
        </tbody></table></div>
    </section>;
}
