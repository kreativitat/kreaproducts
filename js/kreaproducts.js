(function ($) {
  'use strict';

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function getQueryParam(name) {
    var params = new URLSearchParams(window.location.search);
    return params.get(name);
  }

  function getBomId() {
    var fromUrl = getQueryParam('id');
    if (fromUrl) {
      return fromUrl;
    }
    var fromInput = $('input[name="id"]').first().val();
    return fromInput || '';
  }

  function getBasePath() {
    var path = window.location.pathname || '';
    return path.replace(/\/bom\/bom_card\.php$/, '');
  }

  function getToken() {
    var metaNew = document.querySelector('meta[name="anti-csrf-newtoken"]');
    if (metaNew && metaNew.content) {
      return metaNew.content;
    }
    var token = $('input[name="token"]').first().val();
    if (token) {
      return token;
    }
    var metaCurrent = document.querySelector('meta[name="anti-csrf-currenttoken"]');
    if (metaCurrent && metaCurrent.content) {
      return metaCurrent.content;
    }
    return '';
  }

  function insertRows(data, isEdit) {
    var entityLabel = escapeHtml(data.entity_label || '');
    var entityValue = escapeHtml(data.entity_value || '');
    var dismantleLabel = escapeHtml(data.dismantle_label || '');

    var entityRowHtml = '<tr class="kreap-entity-row">'
      + '<td class="titlefield">' + entityLabel + '</td>'
      + '<td>' + entityValue + '</td>'
      + '</tr>';

    var productId = parseInt(data.product_id, 10) || 0;
    var dismantleRowHtml = '';
    if (productId > 0) {
      var toggleValue = data.dismantle_value ? 0 : 1;
      var toggleIcon = data.dismantle_value ? 'fa-toggle-on font-status4' : 'fa-toggle-off opacitymedium';
      var base = getBasePath();
      var bomId = getBomId();
      var returnAction = getQueryParam('action') || '';
      if (returnAction !== 'edit' && returnAction !== 'create') {
        returnAction = '';
      }
      var returnParam = returnAction ? '&returnaction=' + encodeURIComponent(returnAction) : '';
      var token = getToken();
      var tokenParam = token ? '&token=' + encodeURIComponent(token) : '';
      var toggleUrl = base + '/bom/bom_card.php?id=' + encodeURIComponent(bomId)
        + '&action=toggle_dismantle&product_id=' + productId
        + '&value=' + toggleValue
        + returnParam
        + tokenParam;

      dismantleRowHtml = '<tr class="kreap-dismantle-row">'
        + '<td class="titlefield">' + dismantleLabel + '</td>'
        + '<td><a class="linkobject" href="' + toggleUrl + '" title="' + dismantleLabel + '">'
        + '<span class="fas ' + toggleIcon + '"></span>'
        + '</a></td></tr>';
    }

    if (isEdit) {
      var $table = $('table.tableforfieldedit').first();
      if (!$table.length) {
        return;
      }

      var $warehouseRow = $table.find('tr.field_fk_warehouse').first();
      var $entityRow = $table.find('tr.kreap-entity-row').first();
      if (!$entityRow.length) {
        $entityRow = $table.find('tr.field_entity').first();
      }
      if (!$entityRow.length) {
        $entityRow = $table.find('select[name="kreap_entity"], select[name="entity"]').first().closest('tr');
      }

      if ($entityRow.length) {
        $entityRow.show();
      }

      if (dismantleRowHtml && !$table.find('tr.kreap-dismantle-row').length) {
        if ($warehouseRow.length) {
          $warehouseRow.after(dismantleRowHtml);
        } else {
          $table.prepend(dismantleRowHtml);
        }
      }

      if (!$entityRow.length && entityRowHtml && !$table.find('tr.kreap-entity-row').length) {
        var $dismantleRowInsert = $table.find('tr.kreap-dismantle-row').first();
        if ($dismantleRowInsert.length) {
          $dismantleRowInsert.after(entityRowHtml);
        } else if ($warehouseRow.length) {
          $warehouseRow.after(entityRowHtml);
        } else {
          $table.prepend(entityRowHtml);
        }
        $entityRow = $table.find('tr.kreap-entity-row').first();
      }

      if ($entityRow.length) {
        var $dismantleRow = $table.find('tr.kreap-dismantle-row').first();
        if ($dismantleRow.length) {
          $dismantleRow.after($entityRow);
        } else if ($warehouseRow.length) {
          $warehouseRow.after($entityRow);
        } else {
          $table.prepend($entityRow);
        }
      }
      return;
    }

    var $tables = $('table.tableforfield');
    if (!$tables.length) {
      return;
    }
    var $table = $tables.last();
    $tables.not($table).find('tr.kreap-entity-row, tr.kreap-dismantle-row').remove();

    var $entityRows = $table.find('tr.kreap-entity-row');
    if ($entityRows.length > 1) {
      $entityRows.slice(0, -1).remove();
    }
    var $entityRowView = $table.find('tr.kreap-entity-row').last();
    if (!$entityRowView.length) {
      $table.append(entityRowHtml);
      $entityRowView = $table.find('tr.kreap-entity-row').last();
    }

    var $dismantleRows = $table.find('tr.kreap-dismantle-row');
    if ($dismantleRows.length > 1) {
      $dismantleRows.slice(0, -1).remove();
    }
    var $dismantleRowView = $table.find('tr.kreap-dismantle-row').last();
    if (dismantleRowHtml && !$dismantleRowView.length) {
      if ($entityRowView.length) {
        $entityRowView.before(dismantleRowHtml);
      } else {
        $table.append(dismantleRowHtml);
      }
      $dismantleRowView = $table.find('tr.kreap-dismantle-row').last();
    }

    if ($entityRowView.length && $dismantleRowView.length) {
      var hasEntityAfter = $dismantleRowView.nextAll('tr.kreap-entity-row').length > 0;
      if (!hasEntityAfter) {
        $dismantleRowView.after($entityRowView);
      }
    }
  }

  $(function () {
    if (!/\/bom\/bom_card\.php$/.test(window.location.pathname)) {
      return;
    }

    var action = getQueryParam('action') || '';
    if (action === 'toggle_dismantle') {
      var bomIdRedirect = getBomId();
      if (bomIdRedirect) {
        var returnActionRedirect = getQueryParam('returnaction') || '';
        if (returnActionRedirect !== 'edit' && returnActionRedirect !== 'create') {
          returnActionRedirect = '';
        }
        var target = getBasePath() + '/bom/bom_card.php?id=' + encodeURIComponent(bomIdRedirect);
        if (returnActionRedirect) {
          target += '&action=' + encodeURIComponent(returnActionRedirect);
        }
        window.location.replace(target);
        return;
      }
    }
    var isEdit = action === 'edit';
    var isView = action === '' || action === 'view';
    if (!isEdit && !isView) {
      return;
    }

    var $existingTable = $('table.tableforfield, table.tableforfieldedit').first();
    if ($existingTable.length) {
      var hasDismantle = $existingTable.find('tr.kreap-dismantle-row').length > 0;
      var hasEntity = $existingTable.find('tr.kreap-entity-row').length > 0;
      if (hasDismantle && hasEntity) {
        return;
      }
    }

    var bomId = getBomId();
    if (!bomId) {
      return;
    }

    var base = getBasePath();
    var url = base + '/custom/kreaproducts/ajax/bom_dismantle.php';
    $.ajax({
      url: url,
      method: 'POST',
      dataType: 'json',
      data: {
        id: bomId,
        token: getToken()
      }
    }).done(function (resp) {
      if (!resp || resp.status !== 'success') {
        return;
      }
      insertRows(resp, isEdit);
    });
  });
})(jQuery);
