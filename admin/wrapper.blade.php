<script>
(function () {
  try {
    var menu = document.querySelector('.main-sidebar .sidebar-menu');
    if (!menu || document.getElementById('prm-csplugins-nav')) return;

    var li = document.createElement('li');
    li.id = 'prm-csplugins-nav';
    if (location.pathname.indexOf('/admin/extensions/prmcsplugins') === 0) {
      li.className = 'active';
    }
    li.innerHTML =
      '<a href="/admin/extensions/prmcsplugins">' +
      '<i class="fa fa-puzzle-piece"></i> <span>CS2 Plugin Installer</span>' +
      '</a>';

    var headers = menu.querySelectorAll('li.header');
    var management = null;
    for (var i = 0; i < headers.length; i++) {
      if ((headers[i].textContent || '').toUpperCase().indexOf('MANAGEMENT') !== -1) {
        management = headers[i];
        break;
      }
    }
    if (management && management.nextSibling) {
      menu.insertBefore(li, management.nextSibling);
    } else {
      menu.appendChild(li);
    }
  } catch (e) {}
})();
</script>
