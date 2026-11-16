// AzuraCast Animateurs Virtuels – navigation.
// Served as-is (no build) and injected into every AzuraCast panel page by the plugin.
// 1. Registers the page /station/<id>/animateurs-virtuels inside AzuraCast's own Vue router,
//    reusing the station layout (header, sidebar) so the page looks native.
// 2. Adds "Animateurs virtuels" to the station sidebar, just after "Media".

const ITEM_ID = "animateurs-virtuels-menu-item";
const ERROR_ID = "animateurs-virtuels-error";
const ROUTE_NAME = "animateurs-virtuels:index";
const SUFFIX = "/animateurs-virtuels/assets/navigation.js";
const ICON_PATH = "M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3m5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.48 6-3.3 6-6.72z";

function stationPath(pathname, basePath) {
    const prefix = `${basePath}/station/`;
    if (!pathname.startsWith(prefix)) {
        return null;
    }
    const match = pathname.slice(prefix.length).match(/^([1-9]\d*)(?:\/|$)/);
    return match ? {id: match[1], path: `${prefix}${match[1]}`} : null;
}

function mount(document, window, basePath, version) {
    let router = null;
    let failed = false;
    let pageModule = null;
    let frame = null;

    function showError(error) {
        window.console.error("Animateurs virtuels:", error);
        if (document.getElementById(ERROR_ID)) return;
        const alert = document.createElement("div");
        alert.id = ERROR_ID;
        alert.className = "alert alert-danger m-3";
        alert.setAttribute("role", "alert");
        alert.textContent = "Impossible de charger « Animateurs virtuels ». Rechargez la page ; si le problème persiste, "
            + "relancez bin/setup.sh dans le dossier du plugin.";
        (document.getElementById("content") || document.body).prepend(alert);
    }

    function loadPage() {
        if (!pageModule) {
            const assets = `${basePath}/animateurs-virtuels/assets`;
            const css = new Promise((resolve, reject) => {
                if (document.getElementById("animateurs-virtuels-css")) return resolve();
                const link = document.createElement("link");
                link.id = "animateurs-virtuels-css";
                link.rel = "stylesheet";
                link.href = `${assets}/app.css?v=${version}`;
                link.onload = resolve;
                link.onerror = () => reject(new Error("Feuille de style introuvable"));
                document.head.append(link);
            });
            pageModule = Promise.all([import(`${assets}/app.js?v=${version}`), css])
                .then(([module]) => module.AnimateursVirtuelsPage)
                .catch((error) => {
                    pageModule = null;
                    showError(error);
                    throw error;
                });
        }
        return pageModule;
    }

    function connectRouter() {
        if (router || failed) return;
        const app = document.getElementById("dashboard")?.__vue_app__;
        const nativeRouter = app?.config?.globalProperties?.$router;
        if (!nativeRouter) return;
        try {
            const layout = nativeRouter.getRoutes().find((route) =>
                route.path === "/station/:station_id" && route.children?.some((child) => child.path === ""));
            if (!layout?.components?.default) {
                throw new Error("La mise en page des stations d'AzuraCast est introuvable.");
            }
            if (!nativeRouter.hasRoute(ROUTE_NAME)) {
                nativeRouter.addRoute({
                    path: "/station/:station_id/animateurs-virtuels",
                    component: layout.components.default,
                    children: [{
                        path: "",
                        name: ROUTE_NAME,
                        component: loadPage,
                        props: (route) => ({
                            base: `${basePath}/animateurs-virtuels/${route.params.station_id}`,
                        }),
                    }],
                });
            }
            router = nativeRouter;
            router.afterEach(() => schedule());
            router.isReady().then(() => {
                const route = router.currentRoute.value;
                // A direct link may have been resolved before the route existed.
                if (/^\/station\/[1-9]\d*\/animateurs-virtuels\/?$/.test(route.path) && route.name !== ROUTE_NAME) {
                    router.replace(route.fullPath).catch(showError);
                }
            });
        } catch (error) {
            failed = true;
            showError(error);
        }
    }

    function refresh() {
        frame = null;
        connectRouter();

        const station = stationPath(window.location.pathname, basePath);
        const mediaMenu = document.querySelector("#sidebar-submenu-media");
        const mediaItem = mediaMenu?.closest("li.nav-item");
        const existing = document.getElementById(ITEM_ID);

        // Only shown to users who can see the station's "Music Files" (Media permission).
        const canManage = station && mediaMenu && [...mediaMenu.querySelectorAll("a[href]")].some((link) => {
            const url = new URL(link.href, window.location.href);
            return url.pathname === `${station.path}/files`;
        });
        if (!canManage || !mediaItem) {
            existing?.remove();
            return;
        }

        // Several plugins add their entry after "Media" (Sweepers, Show Downloader…), each with its
        // own script. To never fight over positions (two scripts moving their entries back and forth
        // forever freezes the page), the rule is: an entry already somewhere in the block of plugin
        // entries that follows "Media" (the <li> with an id) is never moved. Only a missing or
        // misplaced entry is put at the end of that block.
        let last = mediaItem;
        let inBlock = false;
        for (let next = mediaItem.nextElementSibling; next?.matches("li.nav-item[id]"); next = next.nextElementSibling) {
            if (next.id === ITEM_ID) inBlock = true;
            else last = next;
        }

        const href = `${station.path}/animateurs-virtuels`;
        const active = window.location.pathname.replace(/\/$/, "") === href;
        if (existing?.dataset.href === href) {
            // Same station: only update the "active" state in place (no DOM move).
            const link = existing.querySelector("a");
            if (existing.dataset.active !== String(active)) {
                existing.dataset.active = String(active);
                link.classList.toggle("active", active);
                if (active) link.setAttribute("aria-current", "page");
                else link.removeAttribute("aria-current");
            }
            if (!inBlock) last.after(existing);
            return;
        }
        existing?.remove();

        const item = document.createElement("li");
        item.id = ITEM_ID;
        item.className = "nav-item";
        item.dataset.href = href;
        item.dataset.active = String(active);

        const link = document.createElement("a");
        link.className = active ? "nav-link active" : "nav-link";
        link.href = href;
        if (active) link.setAttribute("aria-current", "page");

        const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        svg.setAttribute("class", "navdrawer-nav-icon");
        svg.setAttribute("viewBox", "0 0 24 24");
        svg.setAttribute("width", "1em");
        svg.setAttribute("height", "1em");
        svg.setAttribute("aria-hidden", "true");
        const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
        path.setAttribute("fill", "currentColor");
        path.setAttribute("d", ICON_PATH);
        svg.append(path);

        const label = document.createElement("span");
        label.className = "might-overflow";
        label.textContent = "Animateurs virtuels";

        link.append(svg, label);
        item.append(link);
        last.after(item);
    }

    function schedule() {
        if (frame === null) {
            frame = window.requestAnimationFrame(refresh);
        }
    }

    // Delegated click handler: keeps working even if the menu entry is rebuilt.
    document.addEventListener("click", (event) => {
        const link = event.target.closest?.(`#${ITEM_ID} a[href]`);
        if (!router || !link || event.defaultPrevented || event.button > 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin) return;
        event.preventDefault();
        document.getElementById(ERROR_ID)?.remove();
        router.push(url.pathname.slice(basePath.length)).catch(showError);
    });

    new window.MutationObserver(schedule).observe(document.body, {childList: true, subtree: true});
    document.addEventListener("vue-ready", schedule);
    window.addEventListener("popstate", schedule);
    schedule();
}

if (typeof window !== "undefined" && !window.animateursVirtuelsNavigation) {
    const url = new URL(import.meta.url);
    if (url.pathname.endsWith(SUFFIX)) {
        window.animateursVirtuelsNavigation = true;
        mount(document, window, url.pathname.slice(0, -SUFFIX.length), url.searchParams.get("v") || "dev");
    }
}
