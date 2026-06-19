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
        activeDate = dateKey;
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
      button.addEventListener("click", () => {
        eventInput.value = slot.id;
        selectedSlot.textContent = `${dateLabel(slot.dateKey)} · ${slot.timeLabel}`;
        form.hidden = false;
        form.scrollIntoView({ behavior: "smooth", block: "nearest" });
      });
      slotsWrap.appendChild(button);
    });
  };

  const loadSlots = async () => {
    setStatus("Caricamento disponibilità...");
    calendar.hidden = true;
    form.hidden = true;

    try {
      const response = await fetch("api/prenotazioni.php?action=slots", { cache: "no-store" });
      const data = await response.json();
      if (!response.ok || !data.ok) {
        throw new Error(data.message || "Calendario non disponibile.");
      }

      groupSlots(data.slots || []);
      const dates = Array.from(slotsByDate.keys());
      if (!dates.length) {
        setStatus("Al momento non ci sono slot disponibili. Riprova più tardi.", "empty");
        return;
      }

      activeDate = dates[0];
      calendar.hidden = false;
      setStatus("Seleziona un giorno e un orario tra quelli disponibili.", "ok");
      renderDays();
      renderSlots();
    } catch (error) {
      setStatus("Calendario momentaneamente non disponibile. Verifica la configurazione Google Calendar nel CMS.", "error");
    }
  };

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const submit = form.querySelector('button[type="submit"]');
    const formData = new FormData(form);
    const payload = Object.fromEntries(formData.entries());
    payload.privacy = formData.has("privacy");

    submit.disabled = true;
    setStatus("Verifica dello slot e conferma in corso...");

    try {
      const response = await fetch("api/prenotazioni.php?action=book", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await response.json();
      if (!response.ok || !data.ok) {
        throw new Error(data.message || "Prenotazione non riuscita.");
      }

      form.reset();
      form.hidden = true;
      setStatus(data.message || "Prenotazione confermata.", "ok");
      await loadSlots();
    } catch (error) {
      setStatus(error.message || "Questo orario non è più disponibile. Scegli un altro slot.", "error");
      await loadSlots();
    } finally {
      submit.disabled = false;
    }
  });

  loadSlots();
})();
