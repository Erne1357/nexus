from ortools.sat.python import cp_model


def test_ortools_resuelve_un_modelo_minimo():
    """La rueda de OR-Tools corre en la imagen: el modelo real (7.4 a 7.7) parte de aquí."""
    model = cp_model.CpModel()
    x = model.new_int_var(0, 5, "x")
    y = model.new_int_var(0, 5, "y")
    model.add(x + y == 7)
    model.maximize(x)
    solver = cp_model.CpSolver()
    solver.parameters.num_workers = 1

    assert solver.solve(model) == cp_model.OPTIMAL
    assert (solver.value(x), solver.value(y)) == (5, 2)
