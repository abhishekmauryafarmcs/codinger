#include <iostream>

int main() {
  int num1, num2, sum;

  // Prompt the user to enter two integers
  std::cout << "Enter the first integer: ";
  std::cin >> num1;

  std::cout << "Enter the second integer: ";
  std::cin >> num2;

  // Calculate the sum
  sum = num1 + num2;

  // Display the sum
  std::cout << "The sum of " << num1 << " and " << num2 << " is: " << sum << std::endl;

  return 0;
}