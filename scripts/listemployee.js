$(document).ready(function () {
    $('#addEmployeeButton').click(function (event) {
        $('#form1').attr('action', '/addemployee');
    });
    $("#search").keyup(function () {
        var search = $("#search").val();
        if (search === "")
            search = "all";
        $('#employeeList').load('/listemployee/' + search);
    });
});


