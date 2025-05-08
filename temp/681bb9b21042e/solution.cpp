#include <iostream>
#include <vector>
#include <algorithm> // Needed for std::reverse, though we'll do it manually

int main() {
    // Declare a vector to store the input integers.  Using a vector is
    // more flexible than a fixed-size array.
    std::vector<int> numbers;
    int num;

    // Read the integers from the input.  We'll read them one at a time
    // and add them to the vector.
    std::cout << "Enter the three integers: ";
    for (int i = 0; i < 3; ++i) {
        if (std::cin >> num) { // Check if reading an integer was successful
            numbers.push_back(num); // Add the number to the vector
        } else {
            std::cout << "Error: Invalid input.  Please enter integers only." << std::endl;
            return 1; // Return a non-zero value to indicate an error
        }
    }

    // Check if the user entered exactly 3 numbers
    if (numbers.size() != 3) {
        std::cout << "Input must contain exactly 3 integers" << std::endl;
        return 1; // Return a non-zero value to indicate an error
    }

    // Method 1: Manual reversal (more explicit)
    std::vector<int> reversedNumbers;
    reversedNumbers.push_back(numbers[2]); // Last element becomes first
    reversedNumbers.push_back(numbers[1]); // Middle element stays in the middle
    reversedNumbers.push_back(numbers[0]); // First element becomes last

    // Output the reversed array
  
    for (int i = 0; i < reversedNumbers.size(); ++i) {
        std::cout << reversedNumbers[i] << " ";
    }
    std::cout << std::endl;

    // Method 2: In-place reversal using std::reverse (alternative)
    // std::reverse(numbers.begin(), numbers.end()); // reverses the original vector
    // std::cout << "Reversed array (using std::reverse): ";
    // for (int num : numbers) {
    //     std::cout << num << " ";
    // }
    // std::cout << std::endl;
    // Note:  The problem specifically asks to *return* a new array,
    //        not to reverse the original in-place.  So, Method 1 is
    //        more appropriate.  But I've included this for demonstration.

    return 0; // Return 0 to indicate successful execution
}

