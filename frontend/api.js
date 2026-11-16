export function createApi(base) {
    let csrf = "";

    async function request(path, {method = "GET", body = undefined} = {}) {
        const url = new URL(`${base}${path}`, window.location.origin);
        const headers = {"Accept": "application/json"};
        if (method !== "GET") {
            headers["Content-Type"] = "application/json";
            headers["X-CSRF-Token"] = csrf;
        }
        let response;
        try {
            response = await fetch(url, {
                method,
                headers,
                credentials: "same-origin",
                body: body === undefined ? undefined : JSON.stringify(body),
            });
        } catch {
            throw new Error("Le serveur AzuraCast ne répond pas. Vérifiez votre connexion.");
        }
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            if (response.status === 403) {
                throw new Error("Accès refusé : la permission « Gérer les médias » de la station est nécessaire.");
            }
            const error = new Error(data.message || `Erreur du serveur (HTTP ${response.status}).`);
            error.data = data;
            throw error;
        }
        if (data.csrf) csrf = data.csrf;
        return data;
    }

    const post = (path, body = {}) => request(path, {method: "POST", body});

    return {
        state: () => request("/state"),
        save: (settings) => post("/settings", settings),
        history: () => request("/history"),
        clearHistory: () => post("/history/clear"),
        test: (hostId, promptId = "") => post("/test", {host_id: hostId, prompt_id: promptId}),
        broadcast: (jobId) => post(`/broadcast/${encodeURIComponent(jobId)}`),
        checkDeepSeek: (apiKey) => post("/check/deepseek", {api_key: apiKey}),
        checkElevenLabs: (apiKey, voiceId) => post("/check/elevenlabs", {api_key: apiKey, voice_id: voiceId}),
        preview: (payload) => post("/preview", payload),
        clipUrl: (jobId) => `${base}/clips/${encodeURIComponent(jobId)}`,
        library: () => request("/library"),
        libraryDelete: (hostId, id) => post(`/library/${encodeURIComponent(hostId)}/${encodeURIComponent(id)}/delete`),
        libraryDeleteObsolete: (hostId) => post(`/library/${encodeURIComponent(hostId)}/obsolete/delete`),
        libraryAudioUrl: (hostId, id) => `${base}/library/${encodeURIComponent(hostId)}/${encodeURIComponent(id)}/audio`,
    };
}
