<?php
require 'config.php';
require_login();
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <title>Clik2pay – Create & Save Payment Link</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />

  <!-- Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body {
      background: #f0f2f5;
    }

    .page-title {
      font-weight: 600;
      font-size: 1.6rem;
      color: #222;
    }

    .card {
      border-radius: 14px;
      border: none;
    }

    .section-title {
      font-weight: 600;
      font-size: 1.1rem;
      margin-bottom: 6px;
      color: #333;
    }

    .form-label.required::after {
      content: " *";
      color: #dc3545;
    }

    .input-group-text {
      background: #f8f9fa;
      font-weight: 500;
    }

    .mono {
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    }

    .btn-primary,
    .btn-success {
      padding: 0.55rem 1.3rem;
      font-weight: 500;
    }
  </style>
</head>

<body>

  <?php include 'navigation-menu.php'; ?>

  <div class="container" style="max-width: 900px;margin-bottom: 20px;">
    <h1 class="page-title mb-4">Search Client Information</h1>

    <div class="row g-4">
      <div class="col-md-6">
        <label for="contract_number" class="form-label">Contract Number</label>
        <div class="d-flex gap-2">
          <input id="contract_number" class="form-control" placeholder="e.g. 6143">
          <button id="fetchDeal" type="button" class="btn btn-secondary">&gt;</button>
        </div>
      </div>

      <div class="col-md-6">
        <label for="clientemail" class="form-label">Client Email</label>
        <div class="d-flex gap-2">
          <input id="clientemail" type="email" class="form-control" placeholder="client@email.com">
          <button id="fetchuserinfo" type="button" class="btn btn-secondary">&gt;</button>
        </div>
      </div>
    </div>
  </div>

  <div class="container" style="max-width: 900px;">
    <h1 class="page-title mb-4">Create a New Payment Request</h1>

    <div class="card shadow-sm">
      <div class="card-body p-4">
        <form id="payForm" class="row g-4" action="clik2pay_create.php" method="POST">






          <div class="col-md-6">
            <label class="form-label required">Customer City</label>
            <select id="CustomerCity" name="CustomerCity" class="form-select" required>
              <option value="" selected disabled>Select City</option>
              <option>Montreal</option>
              <option>Toronto</option>
              <option>Ottawa</option>
              <option>Vancouver</option>
            </select>
          </div>

          <div class="col-md-6">
            <label for="PaymentType" class="form-label required">Payment Type</label>
            <select id="PaymentType" name="PaymentType" class="form-select" required>
              <option value="" selected disabled>Select Payment Type</option>
              <option value="Deposit">Deposit</option>
              <option value="Repo">Repo</option>
              <option value="Collections">Collections</option>
              <option value="Bounce">Bounce</option>
            </select>
          </div>








          <div class="col-12 pt-2">
            <div class="section-title">Customer Details</div>
          </div>

          <div class="col-md-6">
            <label class="form-label required">Customer Name</label>
            <input id="CustomerName" name="CustomerName" class="form-control" required>
          </div>

          <div class="col-md-6">
            <label class="form-label required">Customer Email</label>
            <input id="CustomerEmail" name="CustomerEmail" class="form-control" required>
          </div>

          <div class="col-md-6">
            <label class="form-label required">Mobile Number</label>
            <input id="MobileNumber" name="MobileNumber" class="form-control" required>
          </div>

          <div class="col-md-3">
            <label class="form-label">Plate #</label>
            <input id="PlateNumber" name="PlateNumber" class="form-control mono">
          </div>

          <div class="col-12 pt-2">
            <div class="section-title">Payment Details</div>
          </div>

          <div class="col-md-3">
            <label class="form-label required">Amount (CAD)</label>
            <input id="Amount" name="Amount" class="form-control" type="number" value="1000" step="0.01" required>
          </div>

          <div class="col-md-7">
            <label class="form-label required">Invoice Number</label>
            <input id="InvoiceNumber" name="InvoiceNumber" class="form-control mono" required>
          </div>

          <!-- Hidden values for case creation -->

          <input type="hidden" name="username" value="<?= htmlspecialchars($_SESSION['email'] ?? '-') ?>">
          <input type="hidden" id="AccountId" name="AccountId">
          <input type="hidden" id="VehicleId" name="VehicleId">
          <input type="hidden" id="ClientPreferredLanguage" name="ClientPreferredLanguage" value="en">


          <div class="col-12 pt-3">
            <button type="submit" style="width: 100%;" class="btn btn-primary">Generate Payment Link</button>
            <div style="text-align: center;color:red;font-size:12px;margin-top: 15px;">
              <p>Payment links are valid for 4 days.<br> Clients must complete their payment before the deadline.<br>
                otherwise, a new payment link will need to be created.</p>
            </div>
          </div>

        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    /* ========= STORE LOCATION RESOLVER ========= */
    function resolveStoreLocation(account) {
      if (!account) return '';
      const state = (account.BillingState || '').toUpperCase();
      const city = (account.BillingCity || '').toLowerCase();

      if (state === 'QC') return 'Montreal';
      if (state === 'BC') return 'Vancouver';
      if (state === 'ON') {
        if (city.includes('toronto')) return 'Toronto';
        if (city.includes('ottawa')) return 'Ottawa';
        return '';
      }
      return '';
    }

    function setCustomerCity(value) {
      const select = document.getElementById('CustomerCity');
      if (!value) return;
      [...select.options].forEach(o => {
        if (o.value === value) select.value = value;
      });
    }

    function mapPreferredLanguage(sfLanguage) {
      if (!sfLanguage) return '';

      switch (sfLanguage.toLowerCase()) {
        case 'english':
          return 'en';
        case 'french':
          return 'fr';
        default:
          return '';
      }
    }


    /* ========= EMAIL SEARCH ========= */
    document.getElementById('fetchuserinfo').addEventListener('click', async () => {
      const email = clientemail.value.trim();
      if (!email) return;

      const res = await fetch('byemailjson.php?email=' + encodeURIComponent(email));
      const data = await res.json();
      if (!data.ok) return;

      const acc = data.account || {};
      CustomerName.value = acc.Name || '';
      CustomerEmail.value = acc.PersonEmail || '';
      MobileNumber.value = acc.PersonMobilePhone || acc.Phone || '';
      AccountId.value = data.accountId || '';

      ClientPreferredLanguage.value = mapPreferredLanguage(acc.PreferredLanguage__pc) || 'en';


      setCustomerCity(resolveStoreLocation(acc));
    });

    /* ========= DEAL SEARCH ========= */
    document.getElementById('fetchDeal').addEventListener('click', async () => {
      const deal = contract_number.value.trim();
      if (!deal) return;

      const res = await fetch('bydealjson.php?DealNumber=' + encodeURIComponent(deal));
      const data = await res.json();
      if (!data.ok) return;


      VehicleId.value = data.vehicle?.Id || '';
      CustomerName.value = data.deal.Formatted_Account_Name__c || '';
      PlateNumber.value = data.deal.License_Plate__c || '';

      InvoiceNumber.value = data.deal.License_Plate__c || '';


      CustomerEmail.value = data.deal.Client_Email_Formula__c || '';
      MobileNumber.value = data.account?.PersonMobilePhone || '';
      AccountId.value = data.account?.Id || '';

      ClientPreferredLanguage.value = mapPreferredLanguage(data.account?.PreferredLanguage__pc) || 'en';


      setCustomerCity(
        data.deal.Store_Location_Name__c ||
        resolveStoreLocation(data.account)
      );
    });
  </script>

</body>

</html>
