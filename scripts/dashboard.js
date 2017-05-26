function update(e, ID) {
    e.preventDefault();
    var result = $("#result").val();
    $.get("/tododone/" + result + "/" + ID, function () {
        console.log("todos updated");
        window.location.replace("/dashboard");
    });
}

