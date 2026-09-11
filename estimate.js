// TODO: укажите ваш ник в Telegram (без @), например 'microdom_servis'
const TG_HANDLE = 'YOUR_TELEGRAM_USERNAME';

document.querySelectorAll('[data-estimate]').forEach((block) => {
  const select = block.querySelector('select');
  const price = block.querySelector('.estimate__price');
  const link = block.querySelector('.estimate__link');
  if (!select || !price || !link || TG_HANDLE === 'YOUR_TELEGRAM_USERNAME') return;

  const update = () => {
    const o = select.options[select.selectedIndex];
    price.textContent = o.dataset.price || '';
    const text = `Здравствуйте! Хочу уточнить стоимость: ${o.text}. Модель ноутбука: `;
    link.href = 'https://t.me/' + TG_HANDLE + '?text=' + encodeURIComponent(text);
  };

  select.addEventListener('change', update);
  update();
});