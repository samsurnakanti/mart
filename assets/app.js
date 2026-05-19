document.addEventListener('DOMContentLoaded', () => {
  const slider = document.querySelector('[data-slider]');
  if (slider) {
    initClassSlider({
      root: slider,
      slideSelector: '.hero-slide',
      dotsSelector: '[data-slider-dots]',
      interval: 5200
    });
  }

  const imageSlider = document.querySelector('[data-image-slider]');
  if (imageSlider) {
    const api = initClassSlider({
      root: imageSlider,
      slideSelector: '.image-slide',
      dotsSelector: '[data-image-dots]',
      interval: 4600
    });
    document.querySelector('[data-image-prev]')?.addEventListener('click', () => api.prev());
    document.querySelector('[data-image-next]')?.addEventListener('click', () => api.next());
  }

  document.querySelectorAll('[data-product-gallery]').forEach((gallery) => {
    const images = Array.from(gallery.querySelectorAll('.gallery-image'));
    const thumbs = Array.from(gallery.querySelectorAll('[data-gallery-thumb]'));
    thumbs.forEach((thumb) => {
      thumb.addEventListener('click', () => {
        const index = Number(thumb.dataset.galleryThumb || 0);
        images.forEach((img, i) => img.classList.toggle('active', i === index));
        thumbs.forEach((btn, i) => btn.classList.toggle('active', i === index));
      });
    });
  });

  document.querySelectorAll('[data-redeem-box]').forEach((box) => {
    const input = box.querySelector('[data-redeem-input]');
    const useMax = box.querySelector('[data-use-max]');
    const saving = box.querySelector('[data-redeem-saving]');
    const total = box.querySelector('[data-redeem-total]');
    const orderTotal = Number(box.dataset.orderTotal || 0);
    const maxRedeem = Number(box.dataset.maxRedeem || 0);

    const money = (value) => `Rs ${value.toFixed(2)}`;

    function render() {
      const raw = Number(input?.value || 0);
      const redeemed = Math.max(0, Math.min(maxRedeem, raw));
      if (input) input.value = String(redeemed);
      if (saving) saving.textContent = money(redeemed);
      if (total) total.textContent = money(Math.max(0, orderTotal - redeemed));
    }

    input?.addEventListener('input', render);
    useMax?.addEventListener('click', () => {
      if (input) input.value = String(maxRedeem);
      render();
    });
    render();
  });
});

function initClassSlider({ root, slideSelector, dotsSelector, interval }) {
  const slides = Array.from(root.querySelectorAll(slideSelector));
  const dotsWrap = root.querySelector(dotsSelector);
  let active = 0;
  let timer = null;

  if (!slides.length || !dotsWrap) {
    return { next() {}, prev() {} };
  }

  slides.forEach((_, index) => {
    const dot = document.createElement('button');
    dot.type = 'button';
    dot.setAttribute('aria-label', `Show slide ${index + 1}`);
    dot.addEventListener('click', () => {
      show(index);
      restart();
    });
    dotsWrap.appendChild(dot);
  });

  const dots = Array.from(dotsWrap.querySelectorAll('button'));

  function show(index) {
    active = (index + slides.length) % slides.length;
    slides.forEach((slide, i) => slide.classList.toggle('active', i === active));
    dots.forEach((dot, i) => dot.classList.toggle('active', i === active));
  }

  function next() {
    show(active + 1);
    restart();
  }

  function prev() {
    show(active - 1);
    restart();
  }

  function restart() {
    if (!interval) return;
    clearInterval(timer);
    timer = setInterval(() => show(active + 1), interval);
  }

  show(0);
  restart();
  return { next, prev };
}
