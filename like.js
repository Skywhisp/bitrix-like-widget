document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.like-btn').forEach((btn) => {
    btn.addEventListener('click', handleLikeClick);
  });
});

async function handleLikeClick(event) {
  const btn = event.currentTarget;

  if (btn.disabled) {
    return;
  }

  const elementId = Number(btn.dataset.elementId);

  if (!Number.isInteger(elementId) || elementId <= 0) {
    console.error('Некорректный data-element-id');
    return;
  }

  const isLiked = btn.classList.contains('like-btn--active');
  const action = isLiked ? 'unlike' : 'like';
  const countEl = btn.querySelector('.like-count');

  btn.disabled = true;

  try {
    const formData = new FormData();

    formData.append('elementId', elementId);
    formData.append('likeAction', action);
    formData.append(
      'sessid',
      typeof BX !== 'undefined' ? BX.bitrix_sessid() : ''
    );

    const response = await fetch(
      '/bitrix/services/main/ajax.php?action=App:Controllers.Like.like',
      {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
      }
    );

    if (!response.ok) {
      throw new Error(`Сетевая ошибка: ${response.status}`);
    }

    const json = await response.json();

    if (json.status !== 'success') {
      const message =
        json.errors?.[0]?.message ||
        'Не удалось обработать лайк';

      throw new Error(message);
    }

    const { count, liked } = json.data;

    if (countEl) {
      countEl.textContent = count;
    }

    btn.classList.toggle('like-btn--active', liked);
    btn.setAttribute('aria-pressed', liked ? 'true' : 'false');
  } catch (error) {
    console.error('Ошибка лайка:', error);
    showToast('Не удалось обновить лайк. Попробуйте ещё раз.');
  } finally {
    btn.disabled = false;
  }
}

function showToast(message) {
  if (
    typeof BX !== 'undefined' &&
    BX.UI &&
    BX.UI.Notification &&
    BX.UI.Notification.Center
  ) {
    BX.UI.Notification.Center.notify({
      content: message
    });

    return;
  }

  alert(message);
}
