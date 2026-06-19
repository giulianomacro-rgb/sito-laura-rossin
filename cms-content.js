(function () {
  const nested = /\/(servizi|iniziative)\//.test(window.location.pathname);
  const prefix = nested ? "../" : "";

  const rootPath = (value) => {
    if (!value) return "";
    if (/^(https?:|mailto:|tel:|#|\/)/.test(value)) return value;
    return prefix + value;
  };

  const setText = (selector, value) => {
    const el = document.querySelector(selector);
    if (el && typeof value === "string") el.textContent = value;
  };

  const setLink = (selector, label, href) => {
    const el = document.querySelector(selector);
    if (!el) return;
    if (typeof label === "string") el.textContent = label;
    if (typeof href === "string") el.setAttribute("href", rootPath(href));
  };

  const setImage = (selector, src, alt) => {
    const el = document.querySelector(selector);
    if (!el || !src) return;
    el.setAttribute("src", rootPath(src));
    if (typeof alt === "string") el.setAttribute("alt", alt);
  };

  const setMeta = (name, value) => {
    if (!value) return;
    const el = document.querySelector(`meta[name="${name}"]`);
    if (el) el.setAttribute("content", value);
  };

  const setProperty = (property, value) => {
    if (!value) return;
    const el = document.querySelector(`meta[property="${property}"]`);
    if (el) el.setAttribute("content", value);
  };

  const isVisible = (value) => value !== false && value !== "0";

  const sortedVisible = (items) =>
    Array.from(items || [])
      .filter((item) => isVisible(item.visible))
      .sort((a, b) => Number(a.order || 0) - Number(b.order || 0));

  const paragraphs = (text) => {
    const fragment = document.createDocumentFragment();
    String(text || "")
      .split(/\n{2,}|\r?\n/)
      .map((part) => part.trim())
      .filter(Boolean)
      .forEach((part) => {
        const p = document.createElement("p");
        p.textContent = part;
        fragment.appendChild(p);
      });
    return fragment;
  };

  const pageKey = () => {
    const path = window.location.pathname;
    if (path.includes("psicoterapia-breve-strategica")) return "psicoterapia";
    if (path.includes("coaching-professionale")) return "coaching";
    if (path.includes("psicologia-del-lavoro")) return "lavoro";
    if (path.includes("mente-adolescente")) return "mente";
    if (path.includes("privacy-policy")) return "privacy";
    if (path.includes("cookie-policy")) return "cookie";
    return "home";
  };

  const renderHome = (site) => {
    const home = site.home || {};
    const assets = site.assets || {};

    if (home.seo?.title) document.title = home.seo.title;
    setMeta("description", home.seo?.description);
    setProperty("og:title", home.seo?.ogTitle || home.seo?.title);
    setProperty("og:description", home.seo?.ogDescription || home.seo?.description);
    setProperty("og:image", assets.ogImage ? new URL(rootPath(assets.ogImage), window.location.href).href : "");

    setImage(".brand img", assets.logo, "Laura Rossin");
    setImage(".site-footer img", assets.logo, "Laura Rossin");
    setImage(".hero-media img", assets.heroImage, "");

    setText(".hero .eyebrow", home.hero?.eyebrow);
    setText("#hero-title", home.hero?.title);
    setText(".hero-lead", home.hero?.lead);
    setLink(".hero-actions .button-primary", home.hero?.primaryLabel, home.hero?.primaryHref);
    setLink(".hero-actions .button-secondary", home.hero?.secondaryLabel, home.hero?.secondaryHref);

    const trustList = document.querySelector(".trust-list");
    if (trustList && Array.isArray(home.trust)) {
      trustList.innerHTML = "";
      home.trust.forEach((item) => {
        const wrap = document.createElement(item.href ? "a" : "div");
        wrap.className = "trust-item";
        if (item.href) {
          wrap.href = rootPath(item.href);
          if (/^https?:\/\//.test(item.href)) {
            wrap.target = "_blank";
            wrap.rel = "noopener";
          }
        }
        const title = document.createElement("span");
        const text = document.createElement("span");
        title.className = "trust-title";
        text.className = "trust-text";
        title.textContent = item.title || "";
        text.textContent = item.text || "";
        wrap.append(title, text);
        trustList.appendChild(wrap);
      });
    }

    setText(".intro-band .eyebrow", home.intro?.eyebrow);
    setText(".intro-band h2", home.intro?.title);
    const introText = document.querySelector(".intro-band .intro-grid > p");
    if (introText && home.intro?.text) introText.textContent = home.intro.text;

    setText("#servizi .section-heading .eyebrow", home.services?.eyebrow);
    setText("#servizi-title", home.services?.title);
    const serviceGrid = document.querySelector(".service-grid");
    if (serviceGrid && Array.isArray(home.services?.items)) {
      serviceGrid.innerHTML = "";
      sortedVisible(home.services.items).forEach((item) => {
        const article = document.createElement("article");
        article.className = "service-card";
        const marker = document.createElement("div");
        marker.className = `service-marker marker-${item.marker || "blue"}`;
        marker.setAttribute("aria-hidden", "true");
        const title = document.createElement("h3");
        title.textContent = item.title || "";
        const p = document.createElement("p");
        p.textContent = item.text || "";
        const a = document.createElement("a");
        a.href = rootPath(item.href || "#");
        a.textContent = item.linkLabel || "Approfondisci";
        article.append(marker, title, p, a);
        serviceGrid.appendChild(article);
      });
    }

    setText(".method-section .eyebrow", home.method?.eyebrow);
    setText("#metodo-title", home.method?.title);
    const methodText = document.querySelector(".method-section .method-grid > div p:not(.eyebrow)");
    if (methodText && home.method?.text) methodText.textContent = home.method.text;
    const steps = document.querySelector(".steps-list");
    if (steps && Array.isArray(home.method?.steps)) {
      steps.innerHTML = "";
      home.method.steps.forEach((item) => {
        const li = document.createElement("li");
        const number = document.createElement("span");
        const strong = document.createElement("strong");
        const text = document.createTextNode(item.text || "");
        number.textContent = item.number || "";
        strong.textContent = item.title || "";
        li.append(number, strong, text);
        steps.appendChild(li);
      });
    }

    const imageGrid = document.querySelector(".image-grid");
    if (imageGrid && Array.isArray(home.gallery)) {
      imageGrid.innerHTML = "";
      home.gallery.forEach((item) => {
        const img = document.createElement("img");
        img.src = rootPath(item.src);
        img.alt = item.alt || "";
        img.loading = "lazy";
        imageGrid.appendChild(img);
      });
    }

    setText(".initiatives-section .eyebrow", home.initiative?.eyebrow);
    setText("#iniziative-title", home.initiative?.title);
    setText(".initiative-label", home.initiative?.label);
    setText(".initiative-panel h3", home.initiative?.name);
    const initiativeText = document.querySelector(".initiative-panel p:not(.initiative-label)");
    if (initiativeText && home.initiative?.text) initiativeText.textContent = home.initiative.text;
    setLink(".initiative-panel .button", home.initiative?.buttonLabel, home.initiative?.href);

    setText(".resources-section .eyebrow", home.topics?.eyebrow);
    setText("#risorse-title", home.topics?.title);
    const topicList = document.querySelector(".topic-list");
    if (topicList && Array.isArray(home.topics?.items)) {
      topicList.innerHTML = "";
      home.topics.items.forEach((item) => {
        const li = document.createElement("li");
        const a = document.createElement("a");
        a.href = rootPath(item.href || "#");
        a.textContent = item.label || "";
        li.appendChild(a);
        topicList.appendChild(li);
      });
    }

    setText(".faq-section .eyebrow", home.faq?.eyebrow);
    setText("#faq-title", home.faq?.title);
    const faqList = document.querySelector(".faq-list");
    if (faqList && Array.isArray(home.faq?.items)) {
      faqList.innerHTML = "";
      home.faq.items.forEach((item) => {
        const details = document.createElement("details");
        const summary = document.createElement("summary");
        const p = document.createElement("p");
        summary.textContent = item.question || "";
        p.textContent = item.answer || "";
        details.append(summary, p);
        faqList.appendChild(details);
      });
    }

    setText(".contact-section .eyebrow", home.contact?.eyebrow);
    setText("#contatti-title", home.contact?.title);
    const contactText = document.querySelector(".contact-section .contact-grid > div p:not(.eyebrow)");
    if (contactText && home.contact?.text) contactText.textContent = home.contact.text;
    const contactCard = document.querySelector(".contact-card");
    if (contactCard && home.contact) {
      contactCard.innerHTML = "";
      const email = document.createElement("a");
      email.href = `mailto:${home.contact.email || ""}`;
      email.textContent = home.contact.email || "";
      const phone = document.createElement("a");
      phone.href = `tel:${home.contact.phoneHref || ""}`;
      phone.textContent = home.contact.phone || "";
      const cities = document.createElement("span");
      cities.textContent = home.contact.cities || "";
      const vat = document.createElement("span");
      vat.textContent = home.contact.vat || "";
      contactCard.append(email, phone, cities, vat);
    }

    const footerLines = document.querySelectorAll(".site-footer p");
    if (footerLines[0] && home.footer?.line1) footerLines[0].textContent = home.footer.line1;
    if (footerLines[1] && home.footer?.line2) footerLines[1].textContent = home.footer.line2;
  };

  const renderPage = (site, key) => {
    const page = site.pages?.[key];
    const assets = site.assets || {};
    const home = site.home || {};
    if (!page) return;

    if (!isVisible(page.published)) {
      const main = document.querySelector("main");
      if (main) {
        main.innerHTML = `
          <section class="section legal-section">
            <div class="container rich-content">
              <p class="eyebrow">Pagina non disponibile</p>
              <h1>Questa pagina non è pubblicata.</h1>
              <p>Il contenuto è momentaneamente nascosto.</p>
              <a class="button button-primary" href="${prefix}index.html">Torna alla homepage</a>
            </div>
          </section>`;
      }
      setMeta("robots", "noindex, nofollow");
      return;
    }

    if (page.seo?.title) document.title = page.seo.title;
    setMeta("description", page.seo?.description);
    setProperty("og:title", page.seo?.title);
    setProperty("og:description", page.seo?.description);
    setProperty("og:image", page.socialImage ? new URL(rootPath(page.socialImage), window.location.href).href : "");
    setImage(".brand img", assets.logo, "Laura Rossin");
    setImage(".site-footer img", assets.logo, "Laura Rossin");

    setText(".page-hero .eyebrow", page.hero?.eyebrow);
    setText(".page-hero h1", page.hero?.title);
    const heroText = document.querySelector(".page-hero p:not(.eyebrow)");
    if (heroText && page.hero?.text) heroText.textContent = page.hero.text;
    setLink(".page-hero .button", page.hero?.buttonLabel, page.hero?.buttonHref);
    setImage(".page-hero img", page.hero?.image, page.hero?.imageAlt);

    const side = document.querySelector(".side-note");
    if (side) {
      side.innerHTML = "";
      const strong = document.createElement("strong");
      strong.textContent = page.sideNoteTitle || "";
      side.appendChild(strong);
      (page.sideNotes || []).forEach((note) => {
        const span = document.createElement("span");
        span.textContent = note;
        side.appendChild(span);
      });
    }

    const rich = document.querySelector(".rich-content");
    if (rich && Array.isArray(page.sections)) {
      rich.innerHTML = "";
      page.sections.forEach((section) => {
        const h2 = document.createElement("h2");
        h2.textContent = section.title || "";
        rich.appendChild(h2);
        rich.appendChild(paragraphs(section.text || ""));
        if (Array.isArray(section.items) && section.items.length) {
          const ul = document.createElement("ul");
          ul.className = "check-list";
          section.items.forEach((item) => {
            const li = document.createElement("li");
            li.textContent = item;
            ul.appendChild(li);
          });
          rich.appendChild(ul);
        }
      });
    }

    const footerLines = document.querySelectorAll(".site-footer p");
    if (footerLines[0] && home.footer?.line1) footerLines[0].textContent = home.footer.line1;
    if (footerLines[1] && home.footer?.line2) footerLines[1].textContent = home.footer.line2;
  };

  const highlightNav = () => {
    const links = Array.from(document.querySelectorAll(".site-nav a"));
    const path = window.location.pathname;
    if (path.includes("/servizi/")) {
      links.find((link) => link.getAttribute("href")?.includes("#servizi"))?.setAttribute("aria-current", "page");
      return;
    }
    if (path.includes("/iniziative/")) {
      links.find((link) => link.getAttribute("href")?.includes("#iniziative"))?.setAttribute("aria-current", "page");
      return;
    }

    const sectionLinks = links
      .map((link) => {
        const href = link.getAttribute("href") || "";
        const id = href.includes("#") ? href.split("#").pop() : "";
        return { link, section: id ? document.getElementById(id) : null };
      })
      .filter((item) => item.section);

    if (!sectionLinks.length) return;

    const updateActive = () => {
      const marker = window.scrollY + window.innerHeight * 0.38;
      const active = sectionLinks.reduce((current, item) => {
        return item.section.offsetTop <= marker ? item : current;
      }, null);
      links.forEach((link) => link.removeAttribute("aria-current"));
      if (active) active.link.setAttribute("aria-current", "page");
    };

    window.addEventListener("scroll", updateActive, { passive: true });
    window.addEventListener("resize", updateActive);
    updateActive();
  };

  fetch(`${prefix}content/site.json`, { cache: "no-store" })
    .then((response) => (response.ok ? response.json() : null))
    .then((site) => {
      if (!site) return;
      const key = pageKey();
      if (key === "home") renderHome(site);
      else renderPage(site, key);
    })
    .catch(() => {});

  document.querySelectorAll(".initiative-scroll").forEach((button) => {
    button.addEventListener("click", () => {
      const track = button.closest(".initiatives-carousel")?.querySelector(".initiative-track");
      if (!track) return;
      const direction = Number(button.dataset.direction || 1);
      const panels = Array.from(track.querySelectorAll(".initiative-panel"));
      if (!panels.length) return;
      const currentIndex = panels.reduce((closest, panel, index) => {
        const distance = Math.abs(panel.offsetLeft - track.scrollLeft);
        return distance < closest.distance ? { index, distance } : closest;
      }, { index: 0, distance: Infinity }).index;
      const nextIndex = Math.max(0, Math.min(panels.length - 1, currentIndex + direction));
      track.scrollTo({ left: panels[nextIndex].offsetLeft, behavior: "smooth" });
    });
  });

  highlightNav();
})();
