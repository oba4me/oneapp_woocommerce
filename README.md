# oneapp_custom_checkout
1app Payment Integration
    <h1>1app Payment Integration</h1>
    <p>Integration Snippets for Popular Form Plugins</p>
    <h2>WPForms</h2>
    <p>Add this JS (in your theme, or via a plugin like WPCode):</p>
    <script>
        <!-- WPForms Example -->
<form id="wpforms-form-123" class="oneapp-form">
  <div class="oneapp-row">
    <input type="text" id="wp_fname" placeholder="First Name" required>
    <input type="text" id="wp_lname" placeholder="Last Name" required>
  </div>
  <div class="oneapp-row">
    <input type="email" id="wp_email" placeholder="Email" required>
    <input type="tel" id="wp_phone" placeholder="Phone" required>
  </div>
  <div class="oneapp-row">
    <input type="number" id="wp_amount" placeholder="Amount (NGN)" required>
  </div>
  <button type="submit" class="oneapp-btn">Pay with 1app</button>
</form>

<script>
document.getElementById('wpforms-form-123').addEventListener('submit', function(e) {
    e.preventDefault();
    window.oneappMakePayment({
        fname: document.getElementById('wp_fname').value,
        lname: document.getElementById('wp_lname').value,
        email: document.getElementById('wp_email').value,
        phone: document.getElementById('wp_phone').value,
        amount: document.getElementById('wp_amount').value,
        reference: 'WPFORM_' + Date.now(),
        onSuccess: function(response) {
            alert('✅ Payment Successful! Reference: ' + response.reference);
            // Optionally submit the form via AJAX or show a thank you message
        },
        onFail: function(response) {
            alert('❌ Payment Failed: ' + response.message);
        }
    });
});
</script>

<!-- Gravity Forms Example -->
<form id="gform_1" class="oneapp-form">
  <div class="oneapp-row">
    <input type="text" id="gf_fname" placeholder="First Name" required>
    <input type="text" id="gf_lname" placeholder="Last Name" required>
  </div>
  <div class="oneapp-row">
    <input type="email" id="gf_email" placeholder="Email" required>
    <input type="tel" id="gf_phone" placeholder="Phone" required>
  </div>
  <div class="oneapp-row">
    <input type="number" id="gf_amount" placeholder="Amount (NGN)" required>
  </div>
  <button type="submit" class="oneapp-btn">Pay with 1app</button>
</form>

<script>
document.getElementById('gform_1').addEventListener('submit', function(e) {
    e.preventDefault();
    window.oneappMakePayment({
        fname: document.getElementById('gf_fname').value,
        lname: document.getElementById('gf_lname').value,
        email: document.getElementById('gf_email').value,
        phone: document.getElementById('gf_phone').value,
        amount: document.getElementById('gf_amount').value,
        reference: 'GF_' + Date.now(),
        onSuccess: function(response) {
            alert('✅ Payment Successful! Reference: ' + response.reference);
        },
        onFail: function(response) {
            alert('❌ Payment Failed: ' + response.message);
        }
    });
});
</script>

<!-- Contact Form 7 Example -->
<form id="cf7-form" class="oneapp-form">
  <div class="oneapp-row">
    <input type="text" name="cf7_fname" id="cf7_fname" placeholder="First Name" required>
    <input type="text" name="cf7_lname" id="cf7_lname" placeholder="Last Name" required>
  </div>
  <div class="oneapp-row">
    <input type="email" name="cf7_email" id="cf7_email" placeholder="Email" required>
    <input type="tel" name="cf7_phone" id="cf7_phone" placeholder="Phone" required>
  </div>
  <div class="oneapp-row">
    <input type="number" name="cf7_amount" id="cf7_amount" placeholder="Amount (NGN)" required>
  </div>
  <button type="submit" class="oneapp-btn">Pay with 1app</button>
</form>

<script>
document.getElementById('cf7-form').addEventListener('submit', function(e) {
    e.preventDefault();
    window.oneappMakePayment({
        fname: document.getElementById('cf7_fname').value,
        lname: document.getElementById('cf7_lname').value,
        email: document.getElementById('cf7_email').value,
        phone: document.getElementById('cf7_phone').value,
        amount: document.getElementById('cf7_amount').value,
        reference: 'CF7_' + Date.now(),
        onSuccess: function(response) {
            alert('✅ Payment Successful! Reference: ' + response.reference);
        },
        onFail: function(response) {
            alert('❌ Payment Failed: ' + response.message);
        }
    });
});
</script>
<----Sytling---->
<style>
.oneapp-form { max-width: 420px; margin: 2em auto; background: #fff; padding: 2em 1.5em; border-radius: 10px; box-shadow: 0 2px 12px #0001; }
.oneapp-row { display: flex; gap: 1em; margin-bottom: 1em; }
.oneapp-form input { flex: 1; padding: 0.7em; border: 1px solid #ddd; border-radius: 5px; font-size: 1em; }
.oneapp-btn { width: 100%; padding: 0.9em; background: #0a7cff; color: #fff; border: none; border-radius: 5px; font-size: 1.1em; cursor: pointer; transition: background 0.2s; }
.oneapp-btn:hover { background: #005bb5; }
</style>

<!----Instruction---->
-Add [oneapp_payment_js] to the page (once).
-Use the relevant form and JS for your plugin.
-Adjust field IDs/names as needed to match your actual form fields.

