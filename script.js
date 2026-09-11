const burger = document.getElementById('burger');
const nav = document.getElementById('nav');

burger.addEventListener('click', () => {
  burger.classList.toggle('active');
  nav.classList.toggle('open');
});

nav.querySelectorAll('.nav__link').forEach((link) => {
  link.addEventListener('click', () => {
    burger.classList.remove('active');
    nav.classList.remove('open');
  });
});

const form = document.getElementById('contactForm');

if (form) {
  const statusEl = document.getElementById('formStatus');
  const PHONE_RE = /^(?:\+?375|80)\s?\(?\d{2}\)?[\s-]?\d{3}[\s-]?\d{2}[\s-]?\d{2}$/;

  const formStart = Date.now();

  fetch('token.php')
    .then((res) => res.json())
    .then((t) => {
      if (form && t && t.token) {
        const csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = 'csrf';
        csrf.value = t.token;
        form.appendChild(csrf);
      }
    })
    .catch(() => {});

  form.addEventListener('submit', (e) => {
    e.preventDefault();

    const name = form.name.value.trim();
    const phone = form.phone.value.trim();

    let error = '';
    if (!name) {
      error = 'Укажите ваше имя';
    } else if (!phone || !PHONE_RE.test(phone)) {
      error = 'Укажите корректный номер, например +375 (29) 123-45-67';
    }

    setFieldStates(error);
    if (error) {
      showStatus(error, 'err');
      return;
    }

    const data = new FormData(form);
    data.set('name', name);
    data.set('phone', phone);
    data.set('start', String(formStart));
    data.set('sent_at', String(Date.now()));

    fetch('send.php', { method: 'POST', body: data })
      .then((res) => res.json())
      .then((result) => {
        if (result.ok) {
          showStatus('Спасибо! Заявка отправлена, перезвоним в течение рабочего дня.', 'ok');
          form.reset();
        } else {
          showStatus(result.error || 'Не удалось отправить. Позвоните нам по телефону.', 'err');
        }
      })
      .catch(() => {
        showStatus('Не удалось отправить. Позвоните нам: +375 (17) 335-27-55', 'err');
      });
  });

  function setFieldStates(errorMsg) {
    const map = {
      name: !form.name.value.trim(),
      phone: !form.phone.value.trim(),
    };
    if (errorMsg === 'Укажите ваше имя') map.name = true;
    if (errorMsg && errorMsg.includes('номер')) map.phone = true;

    const inputs = {
      name: form.name,
      phone: form.phone,
    };
    for (const key of Object.keys(inputs)) {
      inputs[key].classList.toggle('field__input--invalid', !!map[key]);
    }
    form.phone.addEventListener('input', () => form.phone.classList.remove('field__input--invalid'), { once: true });
    form.name.addEventListener('input', () => form.name.classList.remove('field__input--invalid'), { once: true });
  }

  function showStatus(text, type) {
    statusEl.textContent = text;
    statusEl.className = 'contact-form__status ' + (type === 'ok' ? 'ok' : 'err');
  }
}