(function () {
  const widget = document.querySelector("[data-booking]");
  if (!widget) return;

  const status = widget.querySelector(".booking-status");
  const calendar = widget.querySelector(".booking-calendar");
  const daysWrap = widget.querySelector(".booking-days");
  const slotsWrap = widget.querySelector(".booking-slots");
  const form = widget.querySelector(".booking-form");
  const selectedSlot = widget.querySelector(".selected-slot");
  const eventInput = form.querySelector('[name="eventId"]');
  let slotsByDate = new Map();
  let activeDate = "";
  let submitting = false;

  const setStatus = (message, type = "") => {
    status.textContent = message;
    status.dataset.type = type;
  };

  const groupSlots = (slots) => {
    slotsByDate = new Map();
    slots.forEach((slot) => {
      if (!slotsByDate.has(slot.dateKey)) slotsByDate.set(slot.dateKey, []);
      slotsByDate.get(slot.dateKey).push(slot);
    });
  };

  const dateLabel = (dateKey) => {
    const date = new Date(dateKey + "T12:00:00");
    return new Intl.DateTimeFormat("it-IT", {
      weekday: "long",
      day: "numeric",
      month: "long",
    }).format(date);
  };

  const renderDays = () => {
    daysWrap.innerHTML = "";
    const dates = Array.from(slotsByDate.keys());
    dates.forEach((dateKey) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "booking-day";
      button.textContent = dateLabel(dateKey);
      button.setAttribute("aria-pressed", dateKey === activeDate ? "true" : "false");
      button.addEventListener("click", () => {
        if (submitting) return;
        activeDate = dateKey;
        eventInput.value = "";
        form.hidden = true;
        renderDays();
        renderSlots();
      });
      daysWrap.appendChild(button);
    });
  };

  const renderSlots = () => {
    slotsWrap.innerHTML = "";
    const slots = slotsByDate.get(activeDate) || [];
    slots.forEach((slot) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "booking-slot";
      button.textContent = slot.timeLabel;
      button.setAttribute("aria-pressed", String(eventInput.value === slot.id));
      button.addEventListener("click", () => {
        if (submitting) return;
        eventInput.value = slot.id;
        slotsWrap.querySelectorAll("button").forEach((item) => item.setAttribute("aria-pressed", String(item === button)));
        selectedSlot.textContent = `${dateLabel(slot.dateKey)} · ${slot.timeLabel}`;
        form.hidden = false;
        form.scrollIntoView({ behavior: "smooth", block: "nearest" });
      });
      slotsWrap.appendChild(button);
    });
  };

  const loadSlots = async (notice = "", type = "") => {
    if (!notice) setStatus("Caricamento disponibilità...");
    calendar.hidden = true;
    form.hidden = true;
    eventInput.value = "";

    try {
      const response = await fetch("api/prenotazioni.php?action=slots", { cache: "no-store" });
      const data = await response.json();
      if (!response.ok || !data.ok) {
        throw new Error(data.message || "Calendario non disponibile.");
      }

      groupSlots(data.slots || []);
      const dates = Array.from(slotsByDate.keys());
      if (!dates.length) {
        setStatus(notice || "Al momento non ci sono orari disponibili. Contatta Laura per informazioni.", type || "empty");
        return;
      }

      if (!slotsByDate.has(activeDate)) activeDate = dates[0];
      calendar.hidden = false;
      setStatus(notice || "Seleziona un giorno e un orario tra quelli disponibili.", type || "ok");
      renderDays();
      renderSlots();
    } catch (error) {
      setStatus(notice || "Le prenotazioni online non sono disponibili. Per fissare un appuntamento, contatta Laura.", type || "error");
    }
  };

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (submitting || !eventInput.value) return;
    const submit = form.querySelector('button[type="submit"]');
    const formData = new FormData(form);
    const payload = Object.fromEntries(formData.entries());
    payload.privacy = formData.has("privacy");

    submitting = submit.disabled = true;
    setStatus("Verifica dello slot e conferma in corso...");

    try {
      const response = await fetch("api/prenotazioni.php?action=book", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await response.json();
      if (response.status === 409) {
        await loadSlots(data.message || "Questo orario non è più disponibile. Scegli un altro slot.", "error");
        return;
      }
      if (!response.ok || !data.ok) {
        throw new Error(data.message || "Prenotazione non riuscita.");
      }

      form.reset();
      form.hidden = true;
      await loadSlots(data.message || "Prenotazione confermata.", "ok");
    } catch (error) {
      setStatus(error.message || "Non è stato possibile confermare la prenotazione.", "error");
    } finally {
      submitting = submit.disabled = false;
    }
  });

  loadSlots();
})();
