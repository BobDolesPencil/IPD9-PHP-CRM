$(document).ready(function () {

    $("#one").attr('checked', true);
    $("#tblhard").hide();
});
function myFunction1() {
    $("#tblhard").hide();
    $("#tblsoft").show();
}
function myFunction2() {
    $("#tblsoft").hide();
    $("#tblhard").show();
}

