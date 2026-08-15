const API_BASE = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api";

export async function apiRequest(endpoint: string, options: RequestInit = {}) {
    const token = typeof window !== "undefined" ? localStorage.getItem("al-manahel-token") : null;
    
    const headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        ...(token ? { "Authorization": `Bearer ${token}` } : {}),
        ...options.headers,
    };

    const res = await fetch(`${API_BASE}${endpoint}`, {
        ...options,
        headers,
    });

    if (res.status === 401 && typeof window !== "undefined") {
        localStorage.removeItem("al-manahel-token");
        const locale = window.location.pathname.startsWith("/ar") ? "ar" : "fa";
        window.location.href = `/${locale}/login/`;
        throw new Error("Unauthorized");
    }

    if (!res.ok) {
        const error = await res.json().catch(() => ({ message: "An unknown error occurred" }));
        throw new Error(error.message || "Request failed");
    }

    return res.status !== 204 ? res.json() : null;
}

export async function apiUpload(endpoint: string, formData: FormData, method = "POST") {
    const token = typeof window !== "undefined" ? localStorage.getItem("al-manahel-token") : null;

    const res = await fetch(`${API_BASE}${endpoint}`, {
        method,
        headers: {
            Accept: "application/json",
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: formData,
    });

    if (res.status === 401 && typeof window !== "undefined") {
        localStorage.removeItem("al-manahel-token");
        const locale = window.location.pathname.startsWith("/ar") ? "ar" : "fa";
        window.location.href = `/${locale}/login/`;
        throw new Error("Unauthorized");
    }

    if (!res.ok) {
        const error = await res.json().catch(() => ({ message: "An unknown error occurred" }));
        throw new Error(error.message || "Request failed");
    }

    return res.status !== 204 ? res.json() : null;
}

/** Download a binary/text response (e.g. CSV export) as a file. */
export async function apiDownload(endpoint: string, filenameFallback: string) {
    const token = typeof window !== "undefined" ? localStorage.getItem("al-manahel-token") : null;

    const res = await fetch(`${API_BASE}${endpoint}`, {
        headers: {
            Accept: "*/*",
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
    });

    if (res.status === 401 && typeof window !== "undefined") {
        localStorage.removeItem("al-manahel-token");
        const locale = window.location.pathname.startsWith("/ar") ? "ar" : "fa";
        window.location.href = `/${locale}/login/`;
        throw new Error("Unauthorized");
    }

    if (!res.ok) {
        const error = await res.json().catch(() => ({ message: "Download failed" }));
        throw new Error(error.message || "Download failed");
    }

    const blob = await res.blob();
    const disposition = res.headers.get("Content-Disposition") || "";
    const match = disposition.match(/filename="?([^";]+)"?/i);
    const filename = match?.[1] || filenameFallback;

    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}
