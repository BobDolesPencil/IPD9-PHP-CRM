$(document).ready(function () {
    $(".updateLink").hide();
});
function increment(ID) {
    var value = $("input[name=quantity" + ID + "]").val();
    value++;
    $("input[name=quantity" + ID + "]").val(value);
    $("#update" + ID).show();
}
function decrement(ID) {
    var value = $("input[name=quantity" + ID + "]").val();
    if (value > 1) {
        value--;
    }
    $("input[name=quantity" + ID + "]").val(value);
    $("#update" + ID).show();
}
function update(e, ID) {
    e.preventDefault();
    var quantity = $("input[name=quantity" + ID + "]").val();
    $.get("/cart/update/" + ID + "/" + quantity, function () {
        console.log("quantity updated");
        $("#update" + ID).hide();
    });
}



