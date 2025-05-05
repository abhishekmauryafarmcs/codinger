def sum_of_two_numbers():
    """Takes two integers as input and prints their sum."""

    try:
        num1, num2 = map(int, input().split())  # Read two integers from input, separated by space
        sum_of_numbers = num1 + num2
        print(sum_of_numbers)

    except ValueError:
        print("Invalid input. Please enter two integers separated by a space.")


# Call the function to execute the program
sum_of_two_numbers()