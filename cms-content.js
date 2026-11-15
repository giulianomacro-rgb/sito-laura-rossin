(function () {
  const nested = /\/(servizi|iniziative)\//.test(window.location.pathname);
  const prefix = nested ? "../" : "";

  const rootPath = (value) => {
    if (typeof value !== "string") return "";
    value = value.trim();
    if (!value || /[\u0000-\u001f\\]/.test(value) || (/^[a-z][a-z0-9+.-]*:/i.test(value) && !/^(https?|mailto|tel):/i.test(value))) return "";
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

  const cssUrl = (src) => (src ? `url("${rootPath(src).replace(/"/g, "%22")}")` : "");

  const setCssImage = (name, src) => {
    if (!src) return;
    document.documentElement.style.setProperty(name, cssUrl(src));
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

  const applyAssets = (assets = {}) => {
    setCssImage("--pattern-image", assets.patternImage);
    setCssImage("--services-image", assets.servicesIllustration || assets.patternImage);
    setCssImage("--method-image", assets.methodIllustration || assets.patternImage);
    setCssImage("--initiative-image", assets.initiativeIllustration || assets.patternImage);
    setCssImage("--page-image", assets.pageIllustration || assets.patternImage);
    setCssImage("--article-image", assets.articleIllustration || assets.patternImage);
  };

  const renderFooter = (home = {}, assets = {}) => {
    setImage(".site-footer .footer-logo", assets.logo, "Laura Rossin");

    const footerLines = document.querySelectorAll(".site-footer .footer-copy p");
    if (footerLines[0] && home.footer?.line1) footerLines[0].textContent = home.footer.line1;
    if (footerLines[1] && home.footer?.line2) footerLines[1].textContent = home.footer.line2;

    const powered = document.querySelector(".powered-by");
    if (powered) {
      const data = home.footer?.poweredBy || {};
      const label = data.label || "Powered by Archimpresa";
      const logo = data.logo || assets.archimpresaLogo;
      powered.hidden = !label && !logo;
      if (data.href) powered.href = rootPath(data.href);
      else powered.removeAttribute("href");
      const text = powered.querySelector("span");
      const img = powered.querySelector("img");
      if (text) text.textContent = label;
      if (img && logo) {
        img.src = rootPath(logo);
        img.alt = label;
      }
    }
  };

  const renderRichSections = (container, sections = []) => {
    if (!container) return;
    container.innerHTML = "";
    sections.forEach((section) => {
      const h2 = document.createElement("h2");
      h2.textContent = section.title || "";
      container.appendChild(h2);
      container.appendChild(paragraphs(section.text || ""));
      if (Array.isArray(section.items) && section.items.length) {
        const ul = document.createElement("ul");
        ul.className = "check-list";
        section.items.forEach((item) => {
          const li = document.createElement("li");
          li.textContent = item;
          ul.appendChild(li);
        });
        container.appendChild(ul);
      }
    });
  };

  const topicHref = (item) => {
    if (item.href) return item.href;
    if (item.slug) return `articolo.html?slug=${encodeURIComponent(item.slug)}`;
    return "#";
  };

  const pageKey = () => {
    const path = window.location.pathname;
    if (path.includes("articolo.html")) return "article";
    if (path.includes("psicoterapia-breve-strategica")) return "psicoterapia";
    if (path.includes("coaching-professionale")) return "coaching";
    if (path.includes("psicologia-del-lavoro")) return "lavoro";
    if (path.includes("mente-adolescente")) return "mente";
    if (path.includes("privacy-policy")) return "privacy";
    if (path.includes("cookie-policy")) return "cookie";
    for (const key of ["servizi", "metodo", "iniziative", "contatti"]) {
      if (path.endsWith(`/${key}.html`)) return key;
    }
    return "home";
  };

  const renderHome = (site) => {
    const home = site.home || {};
    const assets = site.assets || {};
    applyAssets(assets);

    if (home.seo?.title) document.title = home.seo.title;
    setMeta("description", home.seo?.description);
    setProperty("og:title", home.seo?.ogTitle || home.seo?.title);
    setProperty("og:description", home.seo?.ogDescription || home.seo?.description);
    setProperty("og:image", assets.ogImage ? new URL(rootPath(assets.ogImage), window.location.href).href : "");

    setImage(".brand img", assets.logo, "Laura Rossin");
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
        article.className = "service-card has-illustration";
        const servicePage = Object.values(site.pages || {}).find(page => page.slug === item.href);
        const art = document.createElement("img");
        art.className = "service-illustration";
        art.src = rootPath(servicePage?.hero?.image || assets.servicesIllustration);
        art.alt = "";
        art.loading = "lazy";
        const title = document.createElement("h3");
        title.textContent = item.title || "";
        const p = document.createElement("p");
        p.textContent = item.text || "";
        const a = document.createElement("a");
        a.href = rootPath(item.href || "#");
        a.textContent = item.linkLabel || "Approfondisci";
        article.append(art, title, p, a);
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
      const visibleImages = home.gallery.filter((item) => isVisible(item.visible) && item.src);
      const band = imageGrid.closest(".image-band");
      if (band) band.hidden = visibleImages.length === 0;
      visibleImages.forEach((item) => {
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
      sortedVisible(home.topics.items).forEach((item) => {
        const li = document.createElement("li");
        const a = document.createElement("a");
        a.href = rootPath(topicHref(item));
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

    renderFooter(home, assets);
  };

  const renderPage = (site, key) => {
    const page = site.pages?.[key];
    const assets = site.assets || {};
    const home = site.home || {};
    applyAssets(assets);
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

    setText(".page-hero .eyebrow", page.hero?.eyebrow);
    setText(".page-hero h1", page.hero?.title);
    const heroText = document.querySelector(".page-hero p:not(.eyebrow)");
    if (heroText && page.hero?.text) heroText.textContent = page.hero.text;
    setLink(".page-hero .button", page.hero?.buttonLabel, page.hero?.buttonHref);
    setImage(".page-hero img", page.hero?.image, page.hero?.imageAlt);
    setCssImage("--page-image", page.hero?.image);

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
      renderRichSections(rich, page.sections);
    }

    renderFooter(home, assets);
  };

  const renderArticle = (site) => {
    const home = site.home || {};
    const assets = site.assets || {};
    applyAssets(assets);

    const slug = new URLSearchParams(window.location.search).get("slug") || "";
    const article = (home.topics?.items || []).find((item) => item.slug === slug && isVisible(item.visible));
    setImage(".brand img", assets.logo, "Laura Rossin");
    renderFooter(home, assets);

    if (!article) {
      setMeta("robots", "noindex, nofollow");
      const main = document.querySelector("main");
      if (main) {
        main.innerHTML = `
          <section class="section legal-section">
            <div class="container rich-content">
              <p class="eyebrow">Articolo non disponibile</p>
              <h1>Questo articolo non è pubblicato.</h1>
              <p>Può essere stato nascosto o rimosso dal CMS.</p>
              <a class="button button-primary" href="index.html#risorse-title">Torna agli argomenti</a>
            </div>
          </section>`;
      }
      return;
    }

    document.title = `${article.title || article.label} | Laura Rossin`;
    setMeta("description", article.excerpt);
    setProperty("og:title", article.title || article.label);
    setProperty("og:description", article.excerpt);
    setProperty("og:image", article.socialImage ? new URL(rootPath(article.socialImage), window.location.href).href : "");
    setText(".article-hero .eyebrow", home.topics?.eyebrow || "Argomenti utili");
    setText(".article-hero h1", article.title || article.label);
    const lead = document.querySelector(".article-hero .article-lead");
    if (lead) lead.textContent = article.excerpt || "";
    setImage(".article-hero img", article.heroImage || assets.articleIllustration || assets.patternImage, "");
    renderRichSections(document.querySelector(".article-content"), article.sections || []);
  };

  const highlightNav = () => {
    const links = Array.from(document.querySelectorAll(".site-nav a"));
    const path = window.location.pathname;
    const current = path.includes("/servizi/") ? "servizi.html" : path.includes("/iniziative/") ? "iniziative.html" : path.split("/").pop();
    links.forEach(link => {
      if (link.getAttribute("href")?.split("/").pop() === current) link.setAttribute("aria-current", "page");
      else link.removeAttribute("aria-current");
    });

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

  if (!document.documentElement.hasAttribute('data-server-rendered')) fetch(`${prefix}content/site.json`, { cache: "no-store" })
    .then((response) => (response.ok ? response.json() : null))
    .then((site) => {
      if (!site) return;
      const key = pageKey();
      if (document.querySelector("[data-booking]")) {
        const contact = site.home?.contact || {};
        setImage(".brand img", site.assets?.logo, "Laura Rossin");
        renderFooter(site.home, site.assets);
        if (contact.email) {
          setLink('.booking-contact a[href^="mailto:"]', contact.email, `mailto:${contact.email}`);
          setLink('.hero-actions a[href^="mailto:"]', undefined, `mailto:${contact.email}`);
        }
        if (contact.phoneHref) setLink('.booking-contact a[href^="tel:"]', contact.phone, `tel:${contact.phoneHref}`);
        return;
      }
      if (key === "home") renderHome(site);
      else if (key === "article") renderArticle(site);
      else {
        if (["servizi", "metodo", "iniziative", "contatti"].includes(key)) renderHome(site);
        renderPage(site, key);
      }
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
