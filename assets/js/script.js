document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  if (toggle && sidebar) toggle.addEventListener('click', () => sidebar.classList.toggle('open'));

  document.querySelectorAll('[data-confirm]').forEach(form => {
    form.addEventListener('submit', event => {
      if (!window.confirm(form.dataset.confirm || 'Are you sure?')) event.preventDefault();
    });
  });

  document.querySelectorAll('[data-table-search]').forEach(input => {
    const table = document.querySelector(input.dataset.tableSearch);
    if (!table) return;
    input.addEventListener('input', () => {
      const query = input.value.toLowerCase().trim();
      table.querySelectorAll('tbody tr').forEach(row => {
        row.hidden = !row.textContent.toLowerCase().includes(query);
      });
    });
  });

  document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', event => {
      if (!form.checkValidity()) {
        event.preventDefault();
        form.reportValidity();
      }
    });
  });
});
