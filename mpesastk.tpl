{include file="sections/header.tpl"}

<div class="row">
    <div class="col-sm-12 col-md-12">
        <div class="panel panel-primary panel-hovered panel-stacked mb30">
            <div class="panel-heading">M-Pesa STK Push - Payment Gateway</div>
            <div class="panel-body">
                <form class="form-horizontal" method="post" role="form" action="{$_url}paymentgateway/mpesastk">
                    <div class="form-group">
                        <label class="col-md-2 control-label">Consumer Key</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="mpesastk_consumer_key" name="mpesastk_consumer_key" value="{$mpesastk_consumer_key}" required>
                            <p class="help-block">Your M-Pesa Daraja API Consumer Key</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-2 control-label">Consumer Secret</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="mpesastk_consumer_secret" name="mpesastk_consumer_secret" value="{$mpesastk_consumer_secret}" required>
                            <p class="help-block">Your M-Pesa Daraja API Consumer Secret</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-2 control-label">Business Short Code</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="mpesastk_business_shortcode" name="mpesastk_business_shortcode" value="{$mpesastk_business_shortcode}" required>
                            <p class="help-block">Your M-Pesa Business Short Code (Paybill or Till Number)</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-2 control-label">Passkey</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="mpesastk_passkey" name="mpesastk_passkey" value="{$mpesastk_passkey}" required>
                            <p class="help-block">Your M-Pesa STK Push Passkey</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-2 control-label">Environment</label>
                        <div class="col-md-6">
                            <select class="form-control" id="mpesastk_environment" name="mpesastk_environment" required>
                                <option value="sandbox" {if $mpesastk_environment eq 'sandbox'}selected{/if}>Sandbox (Testing)</option>
                                <option value="production" {if $mpesastk_environment eq 'production'}selected{/if}>Production</option>
                            </select>
                            <p class="help-block">Select Sandbox for testing, Production for live transactions</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-2 control-label">Account Reference</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="mpesastk_account_reference" name="mpesastk_account_reference" value="{$mpesastk_account_reference}" maxlength="12">
                            <p class="help-block">Appears on customer's phone (max 12 chars)</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-2 control-label">Transaction Description</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="mpesastk_transaction_desc" name="mpesastk_transaction_desc" value="{$mpesastk_transaction_desc}" maxlength="20">
                            <p class="help-block">Appears on customer's phone (max 20 chars)</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-2 control-label">Callback URL</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" readonly value="{$mpesastk_callback_url}">
                            <p class="help-block">Set this URL in your M-Pesa Daraja API dashboard</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-2 control-label">Allowed IPs</label>
                        <div class="col-md-6">
                            <textarea class="form-control" rows="4" readonly>196.201.214.200
196.201.214.206
196.201.213.114
196.201.212.127
196.201.212.138
196.201.212.129
196.201.212.136
196.201.212.74
196.201.212.69</textarea>
                            <p class="help-block">Whitelist these Safaricom IPs on your server</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="col-lg-offset-2 col-lg-10">
                            <button class="btn btn-primary" type="submit" name="save" value="mpesastk">{Lang::T('Save Changes')}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}